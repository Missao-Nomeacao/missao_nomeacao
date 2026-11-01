<?php

/**
 * Baixa os relatórios do Coach no Tutory e envia por e-mail aos alunos cadastrados.
 *
 * 1. Login (/intent/login) → sessão + Bearer
 * 2. /alunos/consulta?status=ativos (cards com data-id)
 * 3. Para cada modelo em RELATORIOS[]:
 *    POST /intent/cadastrar-relatorio-coach (agrupamento=dia)
 *    GET /documentos/relatorios/{model}?key=...
 * 4. Monta UM PDF consolidado (Puppeteer se houver Node; senão PHP/Dompdf)
 * 5. Reprocessa falhas (até 3x) por aluno
 * 6. Lista alunos do banco → aluno novato (< 15 dias) recebe só o e-mail de boas-vindas;
 *    os demais: localiza o PDF consolidado → um e-mail com 1 anexo se recebe_email
 * 7. Envia o PDF resumido da execução para o endereço configurado
 */

namespace App\Services\Tutory;

use App\Http\Util\MailHelper;
use App\Models\Aluno;
use App\Services\Alunos\TextoBoasVindasNovato;
use App\Services\Desempenho\AvaliadorDesempenho;
use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Options;
use DOMXPath;
use GuzzleHttp\Cookie\FileCookieJar;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CoachReportDownloader
{
    private const BASE = 'https://admin.tutory.com.br';

    private const ALUNO_TESTE = 'Mychel';

    private const MAX_TENTATIVAS = 3;

    /**
     * Pizza no PDF Dompdf — meio-termo entre o original (~700×327, grande demais)
     * e o recorte 220×160 (pequeno demais). Mesma proporção ~1,38 do recorte,
     * com altura próxima dos demais gráficos (.chart max-height 280px).
     */
    private const PIE_PDF_WIDTH = 400;

    private const PIE_PDF_HEIGHT = 290;

    private const PIE_RENDER_WIDTH = 560;

    private const PIE_RENDER_HEIGHT = 406;

    /**
     * Gráficos de Progresso por Modalidade: cabem na mesma página do título.
     * Dompdf ignora max-height e, com PNG em 2x, joga a imagem para a página seguinte.
     */
    private const MODALIDADE_PDF_WIDTH = 640;

    private const MODALIDADE_PDF_HEIGHT = 280;

    /**
     * @var list<string>
     */
    private const CHARTS_MODALIDADE = [
        'chart_progresso_estudo',
        'chart_progresso_resumo',
        'chart_progresso_revisao',
        'chart_progresso_exercicio',
    ];

    /**
     * Modelos do menu "Gerar Relatório" do mentor (modal #modalGeraRelatorio).
     * model = segmento em /documentos/relatorios/{model}
     *
     * @var list<array{model: string, nome: string, slug: string}>
     */
    private const RELATORIOS = [
        [
            'model' => 'desempenho',
            'nome' => 'Desempenho',
            'slug' => 'desempenho',
        ],
        [
            'model' => 'aluno',
            'nome' => 'Estudos',
            'slug' => 'aluno',
        ],
        [
            'model' => 'horas-liquidas',
            'nome' => 'Horas Líquidas',
            'slug' => 'horas-liquidas',
        ],
        [
            'model' => 'questoes',
            'nome' => 'Desempenho em Questões',
            'slug' => 'questoes',
        ],
        [
            'model' => 'progresso',
            'nome' => 'Progresso do plano',
            'slug' => 'progresso',
        ],
    ];

    private string $urlLogin;

    private string $email;

    private string $senha;

    private string $pastaDownload;

    private int $timeout;

    private string $periodo;

    private bool $teste;

    /**
     * Primeiro dia do mês civil escolhido (contingência). Quando definido,
     * o período 2 não recua para o mês anterior.
     */
    private ?\DateTimeImmutable $referenciaMes;

    private string $cookieFile;

    private ?FileCookieJar $cookieJar = null;

    private ?string $bearerToken = null;

    /** @var list<string> */
    private array $endpointsGeracao = [];

    /** @var callable(string): void */
    private $logger;

    private ?ResumoExecucaoRelatorio $resumoExecucao = null;

    /**
     * Ativos da Tutory nesta execução (id + nome), para não tratar cadastro
     * local leftover como "PDF não localizado".
     *
     * @var list<array{nome: string, id: string}>
     */
    private array $alunosAtivosTutory = [];

    private ?string $ultimoErroPdf = null;

    public function __construct(
        string $periodo,
        ?callable $logger = null,
        bool $teste = false,
        ?\DateTimeInterface $referenciaMes = null,
    ) {
        $this->periodo = $periodo;
        $this->teste = $teste;
        $this->logger = $logger ?? static function (string $message): void {
            echo $message.PHP_EOL;
        };
        $this->referenciaMes = $referenciaMes === null
            ? null
            : ($referenciaMes instanceof \DateTimeImmutable
                ? $referenciaMes
                : \DateTimeImmutable::createFromInterface($referenciaMes)
            )->modify('first day of this month')->setTime(0, 0, 0);

        $loginUrl = trim((string) env('LOGIN_URL', ''));
        $this->urlLogin = $loginUrl !== '' ? $loginUrl : 'https://admin.tutory.com.br/login';
        // TEMP (teste): credenciais hardcoded — trocar por .env depois
        $this->senha = '05473793150';
        $this->email = 'missaonomeacao';
        $pastaEnv = trim((string) env('PASTA_DOWNLOAD', ''));
        $defaultPasta = function_exists('public_path')
            ? public_path('pdfs')
            : (function_exists('storage_path')
                ? storage_path('app/tutory-relatorios')
                : rtrim((string) getenv('HOME'), '/').'/Relatorios_Tutory');
        $this->pastaDownload = $this->expandHome($pastaEnv !== '' ? $pastaEnv : $defaultPasta);

        $this->timeout = (int) (env('TIMEOUT') ?: 120);
        $this->cookieFile = sys_get_temp_dir().'/tutory_cookies_'.getmypid().'.json';
        $this->cookieJar = new FileCookieJar($this->cookieFile, true);

        if (! is_dir($this->pastaDownload)) {
            mkdir($this->pastaDownload, 0775, true);
        }
    }

    public function run(): int
    {
        $this->validarConfig();
        [$dtIni, $dtFim] = $this->datasPeriodoBr();
        $this->resumoExecucao = new ResumoExecucaoRelatorio(
            inicio: new \DateTimeImmutable('now'),
            periodoRotulo: $dtIni.' a '.$dtFim,
            motor: $this->motivoSemPuppeteer() === null ? 'Puppeteer' : 'Dompdf',
            chaveEnvio: 'tutory.envio.periodo.'.$this->periodo.'.'.$this->mesDoPeriodo()->format('Y-m'),
            pasta: $this->pastaDownload,
            teste: $this->teste,
            fuso: (string) config('app.timezone', 'America/Sao_Paulo'),
        );

        try {
            $this->login();
            $this->baixarTodos();
            $this->enviarEmailsDosAlunos();

            return 0;
        } catch (Throwable $exc) {
            $this->resumoExecucao->erroFatal = $exc->getMessage();
            $this->log('ERRO FATAL:');
            $this->log((string) $exc);
            throw $exc;
        } finally {
            $this->limparCookie();
            $this->enviarResumoDaExecucao();
        }
    }

    private function enviarResumoDaExecucao(): void
    {
        if ($this->resumoExecucao === null) {
            return;
        }

        if ($this->resumoExecucao->fim === null) {
            $this->resumoExecucao->fim = new \DateTimeImmutable('now');
        }

        try {
            $pdf = (new EnvioResumoExecucaoRelatorio)->enviar($this->resumoExecucao);
            if ($pdf === null) {
                $this->log('Resumo da execução não enviado: destinatário ausente.');

                return;
            }
            $this->log('Resumo da execução enviado para '.config('mail.relatorio_execucao_address').' ('.$pdf.')');
        } catch (Throwable $exc) {
            $this->log('Falha ao enviar o resumo da execução: '.$exc->getMessage());
            Log::warning('Falha ao enviar o resumo da execução dos relatórios', [
                'erro' => $exc->getMessage(),
            ]);
        }
    }

    /**
     * Gera o PDF consolidado de um único aluno e devolve o caminho do arquivo.
     * Não envia e-mail e não apaga o PDF (o admin faz o download).
     * Se o id local estiver velho, localiza o aluno pelo nome na Tutory.
     */
    public function gerarPdfParaAluno(string $tutoryId, string $nome, ?Aluno $alunoLocal = null): string
    {
        $this->validarConfig();
        $tutoryId = trim($tutoryId);
        $nome = trim($nome);
        if ($tutoryId === '' || $nome === '') {
            throw new RuntimeException('Informe o aluno da Tutory (id e nome).');
        }

        $this->ultimoErroPdf = null;

        try {
            $this->login();
            $aluno = $this->resolverAlunoAtual($tutoryId, $nome, $alunoLocal);
            $relatorios = $this->relatorios();
            $arquivo = null;
            for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS; $tentativa++) {
                $this->log("Aluno {$aluno['nome']} id={$aluno['id']} (tentativa {$tentativa}/".self::MAX_TENTATIVAS.')');
                try {
                    $arquivo = $this->processarAlunoConsolidado($aluno, $relatorios);
                } catch (Throwable $exc) {
                    $this->ultimoErroPdf = $exc->getMessage();
                    $this->log("[{$aluno['nome']}] ERRO: ".$exc->getMessage());
                    $arquivo = null;
                }
                if ($arquivo !== null) {
                    return $arquivo;
                }
            }

            throw new RuntimeException($this->mensagemFalhaPdf($aluno['nome'], $aluno['id']));
        } catch (Throwable $exc) {
            $this->log('ERRO FATAL:');
            $this->log((string) $exc);
            throw $exc;
        } finally {
            $this->limparCookie();
        }
    }

    private function limparCookie(): void
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }

    private function validarConfig(): void
    {
        // TEMP: credenciais hardcoded para teste — validação de .env desativada
        if (! in_array($this->periodo, ['1', '2'], true)) {
            throw new RuntimeException('--periodo deve ser 1 ou 2.');
        }
    }

    private function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/').substr($path, 1);
        }

        return $path;
    }

    private function client(): PendingRequest
    {
        $req = Http::withOptions([
            'cookies' => $this->cookieJar,
            'allow_redirects' => true,
            'timeout' => $this->timeout,
            'connect_timeout' => 20,
            'http_errors' => false,
        ])
            ->withHeaders([
                'User-Agent' => 'MissaoNomeacao-TutoryCLI/2.0',
                'Accept' => 'application/json, text/html, */*;q=0.8',
                'X-Requested-With' => 'XMLHttpRequest',
                'Origin' => self::BASE,
                'Referer' => self::BASE.'/alunos/consulta',
            ])
            ->baseUrl(self::BASE);

        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $req = $req->withToken($this->bearerToken);
        }

        return $req;
    }

    private function login(): void
    {
        $this->log('Abrindo página de login...');
        $loginPage = $this->client()->get($this->urlLogin);
        if ($loginPage->status() >= 400) {
            throw new RuntimeException('Não foi possível abrir a página de login (HTTP '.$loginPage->status().').');
        }

        $this->log('Enviando credenciais para /intent/login...');
        $response = $this->client()
            ->asForm()
            ->withHeaders(['Referer' => $this->urlLogin])
            ->post('/intent/login', [
                'account' => $this->email,
                'password' => $this->senha,
            ]);

        $json = $response->json();
        if (! is_array($json) || empty($json['result'])) {
            $erro = is_array($json) ? (string) ($json['error'] ?? 'login falhou') : $response->body();
            throw new RuntimeException('Falha no login: '.$erro);
        }

        $index = $this->client()
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get('/index');
        $this->bearerToken = $this->extrairToken($index->body());
        if ($this->bearerToken === null) {
            // Fallback: algumas contas só embutem adminUser na consulta
            $consulta = $this->client()
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->get('/alunos/consulta', ['status' => 'ativos']);
            $this->bearerToken = $this->extrairToken($consulta->body());
        }
        if ($this->bearerToken !== null) {
            $this->log('Login realizado (Bearer token obtido)');
        } else {
            $this->log('Login realizado (sessão por cookie; token Bearer não encontrado — intents autenticadas podem falhar)');
        }
    }

    private function extrairToken(string $html): ?string
    {
        if (preg_match('/adminUser\s*=\s*\{(.*?)\}\s*;/s', $html, $m)) {
            // Formato do painel: token: '...'  (chave sem aspas)
            if (preg_match('/["\']?token["\']?\s*:\s*["\']([^"\']+)["\']/', $m[1], $t)) {
                return $t[1];
            }
        }
        if (preg_match('#["\']?token["\']?\s*:\s*["\']([A-Za-z0-9._\-+/=]+)["\']#', $html, $t)) {
            return $t[1];
        }

        return null;
    }

    /**
     * Mês civil da quinzena pedida.
     *
     * Período 2 agenda no dia 1 do mês seguinte (routes/console.php). Sem
     * recuar o mês, `Y-m-16`…`Y-m-t` cairia no mês corrente — datas futuras
     * e relatório oficial vazio, embora 16–fim do mês anterior tenha dados.
     */
    private function mesDoPeriodo(?\DateTimeInterface $ref = null): \DateTimeImmutable
    {
        if ($this->referenciaMes !== null && $ref === null) {
            return $this->referenciaMes;
        }

        $hoje = $ref instanceof \DateTimeImmutable
            ? $ref
            : ($ref instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($ref)
                : new \DateTimeImmutable('now'));

        if ($this->periodo === '2' && (int) $hoje->format('j') < 16) {
            return $hoje->modify('first day of last month');
        }

        return $hoje;
    }

    /**
     * @return array{0: string, 1: string} Y-m-d
     */
    private function datasPeriodoIso(?\DateTimeInterface $ref = null): array
    {
        $mes = $this->mesDoPeriodo($ref);
        if ($this->periodo === '1') {
            return [$mes->format('Y-m-01'), $mes->format('Y-m-15')];
        }
        $ultimo = (int) $mes->format('t');

        return [$mes->format('Y-m-16'), $mes->format('Y-m-').str_pad((string) $ultimo, 2, '0', STR_PAD_LEFT)];
    }

    /**
     * @return array{0: string, 1: string} d/m/Y (API Tutory)
     */
    private function datasPeriodoBr(?\DateTimeInterface $ref = null): array
    {
        [$ini, $fim] = $this->datasPeriodoIso($ref);

        return [
            \DateTimeImmutable::createFromFormat('Y-m-d', $ini)->format('d/m/Y'),
            \DateTimeImmutable::createFromFormat('Y-m-d', $fim)->format('d/m/Y'),
        ];
    }

    private function loadDom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function hrefInutil(?string $href): bool
    {
        if ($href === null || $href === '') {
            return true;
        }
        $h = strtolower(trim($href));

        return $h === '#'
            || $h === '#!'
            || str_starts_with($h, '#')
            || str_starts_with($h, 'javascript:');
    }

    /**
     * @return list<array{nome: string, id: string}>
     */
    private function coletarAlunosAtivos(): array
    {
        $this->log('Abrindo /alunos/consulta com status=ativos...');
        $resp = $this->client()->get('/alunos/consulta', ['status' => 'ativos']);
        $html = $resp->body();
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            $resp = $this->client()->asForm()->post('/alunos/consulta', ['status' => 'ativos']);
            $html = $resp->body();
        }
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            throw new RuntimeException('Lista de alunos ativos não encontrada em /alunos/consulta.');
        }

        // Endpoints reais da página (ex.: /intent/cadastrar-relatorio-coach)
        if (preg_match_all('#/intent/[a-zA-Z0-9_./-]+#', $html, $m)) {
            $this->endpointsGeracao = array_values(array_unique($m[0]));
            $this->log('Intents na página: '.implode(', ', $this->endpointsGeracao));
        }

        $alunos = [];
        $vistos = [];
        $pagina = 1;
        $urlAtual = self::BASE.'/alunos/consulta?status=ativos';

        while (true) {
            $paginaAlunos = $this->parseAlunosDaPagina($html);
            $this->log("Coletando página {$pagina}: ".count($paginaAlunos).' aluno(s)');
            foreach ($paginaAlunos as $aluno) {
                if ($aluno['id'] === '' || isset($vistos[$aluno['id']])) {
                    continue;
                }
                $vistos[$aluno['id']] = true;
                $alunos[] = $aluno;
            }

            $proxima = $this->proximaPaginaUrl($html, $urlAtual);
            if ($proxima === null) {
                break;
            }
            $this->log('Próxima página: '.$proxima);
            $html = $this->client()->get($proxima)->body();
            $urlAtual = $proxima;
            $pagina++;
            if ($pagina > 100) {
                break;
            }
        }

        $this->log('Total de alunos ativos: '.count($alunos));
        $this->alunosAtivosTutory = $alunos;

        return $alunos;
    }

    /**
     * Troca o id local pelo atual da Tutory quando o cadastro foi recriado.
     *
     * @return array{nome: string, id: string}
     */
    private function resolverAlunoAtual(string $tutoryId, string $nome, ?Aluno $alunoLocal): array
    {
        $ativos = $this->coletarAlunosAtivos();
        $achado = $this->localizarAlunoNaLista($tutoryId, $nome, $ativos);
        $fonte = 'ativos';
        if ($achado === null) {
            $desativados = $this->coletarAlunosDesativados();
            $achado = $this->localizarAlunoNaLista($tutoryId, $nome, $desativados);
            $fonte = 'desativados';
        }
        if ($achado === null) {
            throw new RuntimeException(
                $nome.' não está na Tutory com o id '.$tutoryId
                .' nem pelo nome (ativos ou desativados). Sincronize os alunos e tente de novo.'
            );
        }

        if ($achado['id'] !== $tutoryId) {
            $this->log("[{$nome}] tutory_id local {$tutoryId} → Tutory {$achado['id']} ({$fonte}).");
        } else {
            $this->log("[{$nome}] Encontrada na Tutory ({$fonte}) com id {$achado['id']}.");
        }

        if ($alunoLocal !== null) {
            $mudou = false;
            if ((string) $alunoLocal->tutory_id !== $achado['id']) {
                $alunoLocal->tutory_id = $achado['id'];
                $mudou = true;
            }
            if ($fonte === 'ativos' && ! $alunoLocal->ativo) {
                $alunoLocal->ativo = true;
                $mudou = true;
            }
            if ($fonte === 'desativados' && $alunoLocal->ativo) {
                $alunoLocal->ativo = false;
                $mudou = true;
            }
            if ($mudou) {
                try {
                    $alunoLocal->save();
                } catch (Throwable $exc) {
                    $this->log("[{$nome}] Não atualizei o cadastro local: ".$exc->getMessage());
                }
            }
        }

        return ['id' => $achado['id'], 'nome' => $achado['nome'] !== '' ? $achado['nome'] : $nome];
    }

    /**
     * @param  list<array{nome: string, id: string}>  $lista
     * @return array{nome: string, id: string}|null
     */
    private function localizarAlunoNaLista(string $tutoryId, string $nome, array $lista): ?array
    {
        foreach ($lista as $item) {
            if ($tutoryId !== '' && trim((string) $item['id']) === $tutoryId) {
                return $item;
            }
        }

        $nomeLocal = $this->sanitizarNomeArquivo($nome);
        foreach ($lista as $item) {
            if ($this->nomesArquivoSaoCompativeis($nomeLocal, $this->sanitizarNomeArquivo($item['nome']))) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<array{nome: string, id: string}>
     */
    private function coletarAlunosDesativados(): array
    {
        $this->log('Abrindo /alunos/consulta com status=desativados...');
        $resp = $this->client()->get('/alunos/consulta', ['status' => 'desativados']);
        $html = $resp->body();
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            $resp = $this->client()->asForm()->post('/alunos/consulta', ['status' => 'desativados']);
            $html = $resp->body();
        }
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            $this->log('Lista de desativados não encontrada em /alunos/consulta.');

            return [];
        }

        $alunos = [];
        $vistos = [];
        $pagina = 1;
        $urlAtual = self::BASE.'/alunos/consulta?status=desativados';

        while (true) {
            $paginaAlunos = $this->parseAlunosDaPagina($html);
            $this->log("Coletando desativados página {$pagina}: ".count($paginaAlunos).' aluno(s)');
            foreach ($paginaAlunos as $aluno) {
                if ($aluno['id'] === '' || isset($vistos[$aluno['id']])) {
                    continue;
                }
                $vistos[$aluno['id']] = true;
                $alunos[] = $aluno;
            }

            $proxima = $this->proximaPaginaUrl($html, $urlAtual);
            if ($proxima === null) {
                break;
            }
            $html = $this->client()->get($proxima)->body();
            $urlAtual = $proxima;
            $pagina++;
            if ($pagina > 100) {
                break;
            }
        }

        $this->log('Total de alunos desativados: '.count($alunos));

        return $alunos;
    }

    private function mensagemFalhaPdf(string $nome, string $tutoryId): string
    {
        $base = 'Não foi possível gerar o PDF consolidado para '.$nome.' (id '.$tutoryId.').';
        $detalhe = trim((string) $this->ultimoErroPdf);
        if ($detalhe === '' || $detalhe === $base) {
            return $base.' Veja o overlay e storage/logs/pdf-contingencia.log.';
        }

        return $base.' '.$detalhe;
    }

    /**
     * @return list<array{nome: string, id: string}>
     */
    private function parseAlunosDaPagina(string $html): array
    {
        $xp = $this->loadDom($html);
        $cards = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' pesquisa-aluno-container ')]");
        $alunos = [];
        if ($cards === false) {
            return [];
        }

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }
            $nomeNodes = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' pesquisa-aluno-nome ')]", $card);
            $nome = ($nomeNodes !== false && $nomeNodes->length > 0)
                ? trim((string) $nomeNodes->item(0)?->textContent)
                : '';
            if ($nome === '') {
                continue;
            }

            $id = '';
            $links = $xp->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' btn-generate-report ')]", $card);
            if ($links !== false && $links->length > 0 && $links->item(0) instanceof DOMElement) {
                $id = trim($links->item(0)->getAttribute('data-id'));
            }
            if ($id === '') {
                // fallback: value hidden / painel
                $vals = $xp->query('.//*[@value]', $card);
                if ($vals !== false) {
                    foreach ($vals as $el) {
                        if (! $el instanceof DOMElement) {
                            continue;
                        }
                        $v = trim($el->getAttribute('value'));
                        if (preg_match('/^\d{4,}$/', $v)) {
                            $id = $v;
                            break;
                        }
                    }
                }
            }
            if ($id === '') {
                $this->log("AVISO: sem data-id para {$nome} — ignorado");

                continue;
            }

            $alunos[] = ['nome' => $nome, 'id' => $id];
        }

        return $alunos;
    }

    private function proximaPaginaUrl(string $html, string $urlAtual): ?string
    {
        $xp = $this->loadDom($html);
        $queries = [
            "//li[contains(@class,'page-item') and not(contains(@class,'disabled'))]/a[@rel='next']",
            "//a[contains(@class,'page-link') and (@rel='next' or normalize-space()='›' or normalize-space()='»')]",
        ];
        foreach ($queries as $q) {
            $nodes = $xp->query($q);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $href = trim($node->getAttribute('href'));
                if ($this->hrefInutil($href)) {
                    continue;
                }
                $abs = $this->absolutizar($href, $urlAtual);
                if ($abs !== $urlAtual) {
                    return $abs;
                }
            }
        }

        return null;
    }

    private function absolutizar(string $url, string $base = self::BASE): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, '/')) {
            return self::BASE.$url;
        }

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * @return list<array{model: string, nome: string, slug: string}>
     */
    private function relatorios(): array
    {
        return self::RELATORIOS;
    }

    /**
     * Gera o token e baixa o HTML oficial do modelo (sem PDF individual).
     *
     * @param  array{nome: string, id: string}  $aluno
     * @param  array{model: string, nome: string, slug: string}  $relatorio
     * @return array{url: string, html: string, model: string}|null
     */
    private function abrirPaginaRelatorio(array $aluno, array $relatorio): ?array
    {
        $nome = $aluno['nome'];
        $id = $aluno['id'];
        $model = $relatorio['model'];
        $rotulo = $relatorio['nome'];
        [$dtIniIso, $dtFimIso] = $this->datasPeriodoIso();
        [$dtIni, $dtFim] = $this->datasPeriodoBr();
        $this->log("[{$nome}] [{$rotulo}] id={$id} | Datas: {$dtIniIso} → {$dtFimIso} ({$dtIni} → {$dtFim})");

        $endpoint = trim((string) env('TUTORY_REPORT_GENERATE_URL', ''));
        if ($endpoint === '') {
            $endpoint = '/intent/cadastrar-relatorio-coach';
        }
        if ($this->endpointsGeracao !== [] && ! in_array($endpoint, $this->endpointsGeracao, true)) {
            foreach ($this->endpointsGeracao as $cand) {
                if (str_contains($cand, 'relatorio-coach') || str_contains($cand, 'cadastrar-relatorio')) {
                    $endpoint = $cand;
                    break;
                }
            }
        }

        $this->log("[{$nome}] [{$rotulo}] Gerando via {$endpoint}...");
        // PDF de referência do painel usa agrupamento diário (gráficos por dia)
        $agrupamento = trim((string) env('TUTORY_REPORT_AGRUPAMENTO', 'dia')) ?: 'dia';
        $body = 'alunos[]='.rawurlencode($id)
            .'&dt_ini='.rawurlencode($dtIni)
            .'&dt_fim='.rawurlencode($dtFim)
            .'&agrupamento='.rawurlencode($agrupamento);

        $resp = $this->client()
            ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'])
            ->withBody($body, 'application/x-www-form-urlencoded; charset=UTF-8')
            ->post($endpoint);

        $json = $resp->json();
        if (! is_array($json) || empty($json['result'])) {
            $erro = is_array($json) ? (string) ($json['error'] ?? $resp->body()) : $resp->body();
            $erro = mb_substr(trim($erro), 0, 300);
            $this->ultimoErroPdf = "Falha ao gerar {$rotulo} na Tutory (id {$id}): {$erro}";
            $this->log("[{$nome}] [{$rotulo}] Falha ao gerar: ".$erro);

            return null;
        }

        $lista = $json['data'] ?? null;
        if (! is_array($lista) || $lista === [] || empty($lista[0]['token'])) {
            $corpo = mb_substr(trim($resp->body()), 0, 300);
            $this->ultimoErroPdf = "Resposta sem token de relatório ({$rotulo}, id {$id}): {$corpo}";
            $this->log("[{$nome}] [{$rotulo}] Resposta sem token de relatório: ".$resp->body());

            return null;
        }

        $key = (string) $lista[0]['token'];
        $reportUrl = self::BASE.'/documentos/relatorios/'.$model.'?key='.rawurlencode($key);
        $this->log("[{$nome}] [{$rotulo}] Abrindo relatório (/documentos/relatorios/{$model})...");

        $pagina = $this->client()
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get('/documentos/relatorios/'.$model, ['key' => $key]);

        if ($pagina->status() >= 400 || (! str_contains($pagina->body(), 'btn_save') && ! str_contains($pagina->body(), 'btn_download'))) {
            $this->ultimoErroPdf = "Página do relatório {$rotulo} inválida (HTTP {$pagina->status()}, id {$id}).";
            $this->log("[{$nome}] [{$rotulo}] Página do relatório inválida (HTTP {$pagina->status()})");

            return null;
        }

        $html = $pagina->body();
        if ($model === 'questoes' && (! str_contains($html, 'main-numbers') || ! str_contains($html, 'tabela_questoes'))) {
            $this->log("[{$nome}] [{$rotulo}] AVISO: página sem Breve Panorama ou Performance por assunto");
        }
        if ($model === 'progresso' && ! str_contains($html, 'insights-panel')) {
            $this->log("[{$nome}] [{$rotulo}] AVISO: página sem Painel de Insights");
        }
        if ($model === 'aluno' && ! str_contains($html, 'tabela_revisoes')) {
            $this->log("[{$nome}] [{$rotulo}] AVISO: página sem Revisões no Período (Estudos)");
        }
        if ($model === 'horas-liquidas' && ! str_contains($html, 'chart_line_comparativo')) {
            $this->log("[{$nome}] [{$rotulo}] AVISO: página sem gráfico de desempenho ao longo do tempo");
        }
        if ($model === 'desempenho' && ! str_contains($html, 'main-header-card')) {
            $this->log("[{$nome}] [{$rotulo}] AVISO: página sem cabeçalho moderno de desempenho");
        }

        $this->log("[{$nome}] [{$rotulo}] Página oficial pronta para extração");

        return [
            'url' => $reportUrl,
            'html' => $html,
            'model' => $model,
        ];
    }

    private function pdfContemSecoesObrigatorias(string $caminho, string $model = 'questoes'): bool
    {
        if (! is_file($caminho) || filesize($caminho) < 500) {
            return false;
        }

        $bytes = file_get_contents($caminho);
        if ($bytes === false) {
            return false;
        }

        // Conteúdo oficial do painel vem do PDFWriter/jsPDF.
        // page.pdf() do Chromium altera gráficos (ex.: horas diárias agregadas) e não tem jsPDF.
        $temJsPdf = str_contains($bytes, 'jsPDF');
        $imagens = substr_count($bytes, '/Subtype /Image') + substr_count($bytes, '/Subtype/Image');

        if ($model === 'progresso') {
            $temTexto = str_contains($bytes, 'Progresso')
                || str_contains($bytes, 'Panorama')
                || str_contains($bytes, 'Motivação')
                || str_contains($bytes, 'Motivacao');

            return $temJsPdf && $temTexto && $imagens >= 5;
        }

        if ($model === 'aluno') {
            $temTexto = str_contains($bytes, 'Seu Desempenho')
                || str_contains($bytes, 'Desempenho')
                || str_contains($bytes, 'Estudos')
                || str_contains($bytes, 'Insights');

            return $temJsPdf && $temTexto && $imagens >= 3;
        }

        if ($model === 'horas-liquidas') {
            $temTexto = str_contains($bytes, 'Horas')
                || str_contains($bytes, 'Liquida')
                || str_contains($bytes, 'quidas');

            return $temJsPdf && $temTexto && $imagens >= 2;
        }

        if ($model === 'desempenho') {
            $temTexto = str_contains($bytes, 'Desempenho')
                || str_contains($bytes, 'desempenho')
                || str_contains($bytes, 'Relat');

            return $temJsPdf && $temTexto && $imagens >= 2;
        }

        // jsPDF grava texto em literais PDF; aceita com ou sem acentos escapados
        $temPanorama = str_contains($bytes, 'Breve Panorama') || str_contains($bytes, 'Breve');
        $temAssuntos = str_contains($bytes, 'Performance por assunto')
            || str_contains($bytes, 'Performance por')
            || str_contains($bytes, 'tabela_questoes')
            || str_contains($bytes, 'Taxa de');

        return $temJsPdf && $temPanorama && $temAssuntos;
    }

    private function cookieHeader(): string
    {
        if ($this->cookieJar === null) {
            return '';
        }

        $parts = [];
        foreach ($this->cookieJar as $cookie) {
            $parts[] = $cookie->getName().'='.$cookie->getValue();
        }

        return implode('; ', $parts);
    }

    /**
     * Usa o PDFWriter/jsPDF do painel via Puppeteer → PDF idêntico ao "Baixar".
     */
    private function gerarPdfComPuppeteer(
        string $nome,
        string $reportUrl,
        string $destino,
        string $model = 'questoes',
        string $rotulo = 'Relatório',
    ): bool {
        $script = function_exists('base_path')
            ? base_path('scripts/tutory-render-pdf.mjs')
            : dirname(__DIR__, 3).'/scripts/tutory-render-pdf.mjs';

        if (! is_file($script)) {
            $this->log("[{$nome}] [{$rotulo}] Script Puppeteer ausente: {$script}");

            return false;
        }

        $node = trim((string) env('NODE_BINARY', 'node')) ?: 'node';
        $cmd = [$node, $script, '--url', $reportUrl, '--out', $destino, '--model', $model];
        $cookie = $this->cookieHeader();
        if ($cookie !== '') {
            $cmd[] = '--cookie';
            $cmd[] = $cookie;
        }
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $cmd[] = '--token';
            $cmd[] = $this->bearerToken;
        }

        $this->log("[{$nome}] [{$rotulo}] Renderizando PDF oficial (PDFWriter/jsPDF via Puppeteer)...");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cwd = function_exists('base_path') ? base_path() : dirname($script, 2);
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null);
        if (! is_resource($proc)) {
            $this->log("[{$nome}] [{$rotulo}] Não foi possível iniciar Node/Puppeteer");

            return false;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || ! is_file($destino) || filesize($destino) < 500) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            $this->log("[{$nome}] [{$rotulo}] Puppeteer falhou (exit {$code}): ".$detail);

            return false;
        }

        $this->log("[{$nome}] [{$rotulo}] Arquivo salvo: {$destino}");

        return true;
    }

    /**
     * Extrai as seções pedidas dos 5 HTMLs oficiais e gera um único PDF.
     *
     * @param  array<string, string>  $urlsPorModelo
     */
    private function gerarPdfConsolidadoComPuppeteer(
        string $nome,
        array $urlsPorModelo,
        string $destino,
    ): bool {
        $script = function_exists('base_path')
            ? base_path('scripts/tutory-compose-pdf.mjs')
            : dirname(__DIR__, 3).'/scripts/tutory-compose-pdf.mjs';

        if (! is_file($script)) {
            $this->log("[{$nome}] Script de consolidação ausente: {$script}");

            return false;
        }

        $obrigatorios = ['desempenho', 'aluno', 'horas-liquidas', 'questoes', 'progresso'];
        foreach ($obrigatorios as $model) {
            if (empty($urlsPorModelo[$model])) {
                $this->log("[{$nome}] URL ausente para o modelo {$model}");

                return false;
            }
        }

        $node = $this->binarioNode();
        if ($node === null || ! $this->podeUsarPuppeteer()) {
            return false;
        }

        $cmd = [
            $node,
            $script,
            '--out', $destino,
            '--url-desempenho', $urlsPorModelo['desempenho'],
            '--url-aluno', $urlsPorModelo['aluno'],
            '--url-horas-liquidas', $urlsPorModelo['horas-liquidas'],
            '--url-questoes', $urlsPorModelo['questoes'],
            '--url-progresso', $urlsPorModelo['progresso'],
            '--rotulo-periodo', RelatorioConsolidadoLayout::rotuloPeriodo($this->periodo, $this->mesDoPeriodo()),
        ];
        $cookie = $this->cookieHeader();
        if ($cookie !== '') {
            $cmd[] = '--cookie';
            $cmd[] = $cookie;
        }
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $cmd[] = '--token';
            $cmd[] = $this->bearerToken;
        }

        $this->log("[{$nome}] Compondo relatório único (Puppeteer via {$node})...");

        $resultado = $this->executarProcesso($cmd, function_exists('base_path') ? base_path() : dirname($script, 2));
        if (! $resultado['iniciou']) {
            $this->log("[{$nome}] Não foi possível iniciar Node/Puppeteer (compositor): ".$resultado['erro']);

            return false;
        }

        if ($resultado['code'] !== 0 || ! is_file($destino) || filesize($destino) < 2000) {
            $detail = trim($resultado['stderr'] !== '' ? $resultado['stderr'] : $resultado['stdout']);
            $this->log("[{$nome}] Compositor falhou (exit {$resultado['code']}): ".$detail);

            return false;
        }

        try {
            RelatorioPdfCapas::aplicar($destino);
        } catch (Throwable $exc) {
            $this->log("[{$nome}] Falha ao anexar as capas: ".$exc->getMessage());

            return false;
        }

        $this->log("[{$nome}] Relatório consolidado salvo: {$destino}");

        return true;
    }

    /**
     * @return list<string>
     */
    private function funcoesProcessoBloqueadas(): array
    {
        $raw = strtolower((string) ini_get('disable_functions'));
        if ($raw === '') {
            return [];
        }
        $disabled = array_filter(array_map('trim', explode(',', $raw)));
        $precisa = ['proc_open', 'proc_close'];
        $bloqueadas = [];
        foreach ($precisa as $fn) {
            if (in_array($fn, $disabled, true) || ! function_exists($fn)) {
                $bloqueadas[] = $fn;
            }
        }

        return $bloqueadas;
    }

    private function pathAtualProcesso(): string
    {
        $path = getenv('PATH');

        return is_string($path) && $path !== '' ? $path : '(vazio)';
    }

    /**
     * Hostinger pode ter Node (nodevenv) sem o pacote npm `puppeteer` e sem Chromium.
     * Sem o pacote, o PDF sai pelo Dompdf — não rode `npm install` no shared hosting.
     */
    private function podeUsarPuppeteer(): bool
    {
        return $this->motivoSemPuppeteer() === null;
    }

    /**
     * Motivo para pular o compositor Node. Null = Puppeteer pode ser tentado.
     */
    private function motivoSemPuppeteer(): ?string
    {
        $engine = strtolower(trim((string) env('TUTORY_PDF_ENGINE', 'dompdf')));
        if ($engine === '' || in_array($engine, ['dompdf', 'php'], true)) {
            return 'TUTORY_PDF_ENGINE=dompdf';
        }
        $bloqueadas = $this->funcoesProcessoBloqueadas();
        if ($bloqueadas !== []) {
            return 'funções PHP bloqueadas ('.implode(', ', $bloqueadas).')';
        }
        if ($this->binarioNode() === null) {
            return 'Node.js ausente';
        }
        if (! $this->pacotePuppeteerInstalado()) {
            return 'pacote npm puppeteer ausente (não precisa instalar)';
        }

        return null;
    }

    private function pacotePuppeteerInstalado(): bool
    {
        $root = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        foreach (['puppeteer', 'puppeteer-core'] as $pkg) {
            if (is_file($root.'/node_modules/'.$pkg.'/package.json')) {
                return true;
            }
        }

        return false;
    }

    private function binarioNode(): ?string
    {
        $configurado = trim((string) env('NODE_BINARY', ''));
        $home = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/');
        $candidatos = array_filter([
            $configurado,
            'node',
            'nodejs',
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/opt/alt/alt-nodejs20/root/usr/bin/node',
            '/opt/alt/alt-nodejs18/root/usr/bin/node',
            $home !== '' ? $home.'/bin/node' : null,
        ]);

        foreach ([$home.'/nodevenv', $home.'/.nvm/versions/node'] as $base) {
            if ($base === '/nodevenv' || $base === '/.nvm/versions/node') {
                continue;
            }
            foreach (glob($base.'/*/bin/node') ?: [] as $found) {
                $candidatos[] = $found;
            }
            foreach (glob($base.'/*/*/bin/node') ?: [] as $found) {
                $candidatos[] = $found;
            }
        }

        foreach ($candidatos as $bin) {
            $resolvido = $this->resolverExecutavel((string) $bin);
            if ($resolvido !== null) {
                return $resolvido;
            }
        }

        return null;
    }

    private function resolverExecutavel(string $bin): ?string
    {
        if ($bin === '') {
            return null;
        }
        if (str_contains($bin, '/') || str_starts_with($bin, '.')) {
            return is_executable($bin) ? $bin : null;
        }

        $dirs = explode(':', $this->pathAtualProcesso());
        $home = rtrim((string) (getenv('HOME') ?: ''), '/');
        array_unshift($dirs, '/usr/local/bin', '/usr/bin', $home !== '' ? $home.'/bin' : '');
        foreach (array_filter(array_unique($dirs)) as $dir) {
            $full = rtrim((string) $dir, '/').'/'.$bin;
            if (is_executable($full)) {
                return $full;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $cmd
     * @return array{iniciou: bool, code: int, stdout: string, stderr: string, erro: string}
     */
    private function executarProcesso(array $cmd, string $cwd): array
    {
        $vazio = [
            'iniciou' => false,
            'code' => -1,
            'stdout' => '',
            'stderr' => '',
            'erro' => '',
        ];
        if (! function_exists('proc_open')) {
            $vazio['erro'] = 'proc_open() indisponível neste PHP';

            return $vazio;
        }

        $env = [];
        foreach (['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TMPDIR', 'TMP', 'TEMP'] as $key) {
            $val = getenv($key);
            if (is_string($val) && $val !== '') {
                $env[$key] = $val;
            }
        }
        $env['PATH'] = '/usr/local/bin:/usr/bin:/bin:'.($env['PATH'] ?? '');
        if (isset($env['HOME']) && $env['HOME'] !== '') {
            $env['PATH'] = $env['HOME'].'/bin:'.$env['PATH'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $erro = '';
        set_error_handler(static function (int $severity, string $message) use (&$erro): bool {
            $erro = $message;

            return true;
        });
        try {
            $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($proc)) {
            $vazio['erro'] = $erro !== '' ? $erro : 'proc_open retornou false';

            return $vazio;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'iniciou' => true,
            'code' => proc_close($proc),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'erro' => '',
        ];
    }

    /**
     * @param  array<string, string>  $htmlPorModelo
     */
    private function salvarMetricasConsolidado(string $pdfPath, array $htmlPorModelo): void
    {
        if ($pdfPath === '' || ! is_file($pdfPath)) {
            return;
        }

        $metricas = [];
        if (isset($htmlPorModelo['progresso']) && is_string($htmlPorModelo['progresso'])) {
            $metricas = array_merge($metricas, $this->extrairMetricasConstancia($htmlPorModelo['progresso']));
        }
        if (isset($htmlPorModelo['questoes']) && is_string($htmlPorModelo['questoes'])) {
            $metricas = array_merge($metricas, $this->extrairMetricasQuestoes($htmlPorModelo['questoes']));
        }
        if ($metricas === []) {
            return;
        }

        $sidecar = $this->caminhoMetricasSidecar($pdfPath);
        $payload = [
            'gerado_em' => date('c'),
            'periodo' => $this->periodo,
            'model' => 'consolidado',
            'metricas' => $metricas,
        ];
        file_put_contents($sidecar, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->log('Métricas de desempenho (consolidado) salvas: '.$sidecar);
    }

    /**
     * Fallback sem Node: monta o PDF consolidado com Dompdf + QuickChart
     * a partir dos HTMLs oficiais já baixados.
     *
     * @param  array<string, string>  $htmlPorModelo
     */
    private function gerarPdfConsolidadoDoHtml(string $nome, array $htmlPorModelo, string $destino): bool
    {
        $this->log("[{$nome}] Dompdf: compondo o relatório quinzenal a partir dos HTMLs oficiais...");

        try {
            $dados = $this->montarDadosRelatorio($htmlPorModelo);
            RelatorioQuinzenalPdf::gerar($dados, $destino);
            if (! is_file($destino) || filesize($destino) < 500) {
                $this->log("[{$nome}] Dompdf gerou PDF vazio");

                return false;
            }
            $this->log("[{$nome}] Relatório consolidado (Dompdf) salvo: {$destino}");

            return true;
        } catch (Throwable $exc) {
            $this->log("[{$nome}] Erro Dompdf (consolidado): ".$exc->getMessage());

            return false;
        }
    }

    /**
     * Dados do relatório quinzenal (novo layout), extraídos dos cinco HTMLs oficiais.
     *
     * @param  array<string, string>  $htmlPorModelo
     */
    private function montarDadosRelatorio(array $htmlPorModelo): DadosRelatorioQuinzenal
    {
        $xpDes = $this->loadDom($htmlPorModelo['desempenho'] ?? '');
        $xpAluno = $this->loadDom($htmlPorModelo['aluno'] ?? '');
        $xpHoras = $this->loadDom($htmlPorModelo['horas-liquidas'] ?? '');
        $xpQuestoes = $this->loadDom($htmlPorModelo['questoes'] ?? '');
        $xpProgresso = $this->loadDom($htmlPorModelo['progresso'] ?? '');

        [$aluno, $curso] = $this->extrairIdentidadeDesempenho($xpDes);
        [$ini, $fim] = $this->datasPeriodoIso();

        $planejadas = $this->seriesDoGrafico($htmlPorModelo['progresso'] ?? '', 'chart_horas_diarias', ['planejad', 'meta', 'previst'], ['estudad', 'realizad', 'bruta'], true);
        $comparativo = $this->seriesDoGrafico($htmlPorModelo['horas-liquidas'] ?? '', 'chart_line_comparativo', ['bruta'], ['liquid'], true);
        // Na Tutory: "questões corretas" e "questões erradas".
        $questoesDia = $this->seriesDoGrafico($htmlPorModelo['questoes'] ?? '', 'chart_questoes_dia', ['corret', 'acert', 'cert'], ['errad', 'erro', 'incorret'], false);

        $assuntos = [];
        $tabAssuntos = $this->extrairTabelaPorId($xpQuestoes, 'tabela_questoes');
        foreach ($tabAssuntos['rows'] ?? [] as $linha) {
            if (count($linha) < 2) {
                continue;
            }
            $assuntos[] = [
                'disciplina' => (string) ($linha[0] ?? ''),
                'assunto' => (string) (count($linha) >= 3 ? $linha[1] : ''),
                'taxa' => (string) end($linha),
            ];
        }

        $tabRevisoes = $this->extrairTabelaPorId($xpAluno, 'tabela_revisoes');

        $historico = [];
        $tabHistorico = $this->extrairTabelaPorId($xpHoras, 'tabela_horas_liquidas');
        if ($tabHistorico !== null) {
            $idx = $this->indicesHistorico($tabHistorico['headers']);
            foreach ($tabHistorico['rows'] as $linha) {
                $horas = (string) ($linha[$idx['horas']] ?? '');
                if ($horas === '' && count($linha) < 2) {
                    continue;
                }
                $historico[] = [
                    'disciplina' => (string) ($linha[$idx['disciplina']] ?? ''),
                    'assunto' => (string) ($linha[$idx['assunto']] ?? ''),
                    'modalidade' => (string) ($linha[$idx['modalidade']] ?? ''),
                    'horas' => $horas,
                ];
            }
        }

        return new DadosRelatorioQuinzenal(
            aluno: $aluno,
            curso: $curso,
            inicio: new \DateTimeImmutable($ini),
            fim: new \DateTimeImmutable($fim),
            datas: $this->datasCompactasDoPeriodo(),
            planejado: $planejadas['a'],
            estudado: $planejadas['b'],
            brutas: $comparativo['a'],
            liquidas: $comparativo['b'],
            acertosDia: $questoesDia['a'],
            errosDia: $questoesDia['b'],
            acumulado: $this->paresMetricasDesempenho($xpDes),
            insights: RelatorioConsolidadoLayout::paresInsight($this->textosInsights($xpProgresso)),
            panorama: $this->paresPanorama($xpQuestoes),
            assuntos: $assuntos,
            revisoes: [
                'headers' => $tabRevisoes['headers'] ?? [],
                'rows' => $tabRevisoes['rows'] ?? [],
            ],
            historico: $historico,
        );
    }

    /**
     * Duas séries de um gráfico de barras agrupadas da Tutory, alinhadas às datas do período.
     * A série "b" é a que tem uma das palavras de $chavesB no rótulo (testada antes, porque
     * "incorretas" contém "corretas"); a "a" é a que tem uma das palavras de $chavesA.
     * Rótulo desconhecido ocupa a primeira vaga livre, na ordem do gráfico.
     *
     * @param  list<string>  $chavesA
     * @param  list<string>  $chavesB
     * @return array{a: list<?float>, b: list<?float>}
     */
    private function seriesDoGrafico(string $html, string $canvasId, array $chavesA, array $chavesB, bool $horas): array
    {
        $vazio = ['a' => [], 'b' => []];
        if ($html === '') {
            return $vazio;
        }
        $cfg = $this->extrairChartConfig($html, $canvasId);
        $datasets = is_array($cfg['data']['datasets'] ?? null) ? $cfg['data']['datasets'] : [];
        if ($datasets === []) {
            return $vazio;
        }

        $a = null;
        $b = null;
        $semRotulo = [];
        $contem = static function (string $rotulo, array $chaves): bool {
            foreach ($chaves as $chave) {
                if (str_contains($rotulo, $chave)) {
                    return true;
                }
            }

            return false;
        };
        foreach ($datasets as $ds) {
            if (! is_array($ds)) {
                continue;
            }
            $rotulo = $this->normalizarParaComparacao((string) ($ds['label'] ?? ''));
            $serie = array_map(
                static fn ($v): ?float => is_numeric($v) ? (float) $v : null,
                array_values(is_array($ds['data'] ?? null) ? $ds['data'] : [])
            );
            if ($b === null && $contem($rotulo, $chavesB)) {
                $b = $serie;
            } elseif ($a === null && $contem($rotulo, $chavesA)) {
                $a = $serie;
            } else {
                $semRotulo[] = $serie;
            }
        }
        foreach ($semRotulo as $serie) {
            if ($a === null) {
                $a = $serie;
            } elseif ($b === null) {
                $b = $serie;
            }
        }
        $a ??= [];
        $b ??= [];

        if ($horas) {
            $a = $this->paraHorasDecimais($a);
            $b = $this->paraHorasDecimais($b);
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * A Tutory manda horas decimais; se vier em minutos ou segundos, converte.
     *
     * @param  list<?float>  $serie
     * @return list<?float>
     */
    private function paraHorasDecimais(array $serie): array
    {
        $max = 0.0;
        foreach ($serie as $v) {
            $max = max($max, (float) $v);
        }
        $divisor = $max > 1000 ? 3600.0 : ($max > 24 ? 60.0 : 1.0);
        if ($divisor === 1.0) {
            return $serie;
        }

        return array_map(static fn (?float $v): ?float => $v === null ? null : $v / $divisor, $serie);
    }

    /**
     * @param  list<string>  $headers
     * @return array{disciplina: int, assunto: int, modalidade: int, horas: int}
     */
    private function indicesHistorico(array $headers): array
    {
        $idx = ['disciplina' => 0, 'assunto' => 1, 'modalidade' => 2, 'horas' => 3];
        foreach ($headers as $i => $h) {
            $n = $this->normalizarParaComparacao($h);
            if (str_contains($n, 'disciplina')) {
                $idx['disciplina'] = $i;
            } elseif (str_contains($n, 'assunto')) {
                $idx['assunto'] = $i;
            } elseif (str_contains($n, 'modalidade')) {
                $idx['modalidade'] = $i;
            } elseif (str_contains($n, 'hora') || str_contains($n, 'tempo')) {
                $idx['horas'] = $i;
            }
        }

        return $idx;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function paresMetricasDesempenho(DOMXPath $xp): array
    {
        $cards = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' metric-card ')]");
        if ($cards === false) {
            return [];
        }
        $items = [];
        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }
            $label = '';
            $value = '';
            foreach ($card->getElementsByTagName('p') as $p) {
                $class = ' '.$p->getAttribute('class').' ';
                $txt = trim((string) $p->textContent);
                if (str_contains($class, ' metric-label ')) {
                    $label = $txt;
                }
                if (str_contains($class, ' metric-value ')) {
                    $value = $txt;
                }
            }
            if ($label !== '' || $value !== '') {
                $items[] = ['label' => $label, 'value' => $value];
            }
        }

        return $items;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function paresPanorama(DOMXPath $xp): array
    {
        $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' main-numbers ')]");
        if ($nodes === false) {
            return [];
        }
        $items = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $label = '';
            $value = '';
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'p') {
                    $label = trim((string) $child->textContent);
                }
                if ($tag === 'h3') {
                    $value = trim((string) $child->textContent);
                }
            }
            if ($label !== '' || $value !== '') {
                $items[] = ['label' => $label, 'value' => $value];
            }
        }

        return $items;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extrairIdentidadeDesempenho(DOMXPath $xp): array
    {
        $aluno = $this->xpathText($xp, "//*[contains(concat(' ', normalize-space(@class), ' '), ' aluno-details ')]//h4");
        $curso = $this->xpathText($xp, "//*[contains(concat(' ', normalize-space(@class), ' '), ' aluno-details ')]//p");
        if ($aluno === '') {
            $aluno = $this->xpathText($xp, '//h4') ?: '';
        }

        return [$aluno, $curso];
    }

    private function montarHtmlCabecalhoDesempenho(DOMXPath $xp): string
    {
        [$aluno, $curso] = $this->extrairIdentidadeDesempenho($xp);

        return RelatorioConsolidadoLayout::alunoNome($aluno)
            .($curso !== '' ? '<p class="mn-aluno-curso">'.htmlspecialchars($curso, ENT_QUOTES, 'UTF-8').'</p>' : '');
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, numeric: list<int>}|null
     */
    private function extrairTabelaPorId(DOMXPath $xp, string $id): ?array
    {
        $nodes = $xp->query('//*[@id="'.$id.'"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $table = $nodes->item(0);
        if (! $table instanceof DOMElement) {
            return null;
        }

        $headers = [];
        foreach ($table->getElementsByTagName('th') as $th) {
            $headers[] = trim(preg_replace('/\s+/u', ' ', (string) $th->textContent) ?? '');
        }
        if ($headers === []) {
            $thead = $xp->query('.//thead/tr[1]/*', $table);
            if ($thead !== false) {
                foreach ($thead as $cell) {
                    $headers[] = trim(preg_replace('/\s+/u', ' ', (string) $cell->textContent) ?? '');
                }
            }
        }

        $rows = [];
        $bodyRows = $xp->query('.//tbody/tr', $table);
        if ($bodyRows === false || $bodyRows->length === 0) {
            $bodyRows = $xp->query('.//tr', $table);
        }
        if ($bodyRows !== false) {
            foreach ($bodyRows as $tr) {
                if (! $tr instanceof DOMElement) {
                    continue;
                }
                $parent = $tr->parentNode;
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'thead') {
                    continue;
                }
                $cols = [];
                foreach ($tr->childNodes as $td) {
                    if (! $td instanceof DOMElement) {
                        continue;
                    }
                    $tag = strtolower($td->tagName);
                    if ($tag !== 'td' && $tag !== 'th') {
                        continue;
                    }
                    $cols[] = trim(preg_replace('/\s+/u', ' ', (string) $td->textContent) ?? '');
                }
                if ($cols === []) {
                    continue;
                }
                if ($headers !== [] && $cols === $headers) {
                    continue;
                }
                $rows[] = $cols;
            }
        }

        $numeric = $this->indicesColunasNumericas($headers, $rows);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'numeric' => $numeric,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<int>
     */
    private function indicesColunasNumericas(array $headers, array $rows): array
    {
        $cols = 0;
        foreach ($rows as $r) {
            $cols = max($cols, count($r));
        }
        $cols = max($cols, count($headers));
        $out = [];
        for ($i = 0; $i < $cols; $i++) {
            $valores = [];
            foreach ($rows as $r) {
                if (isset($r[$i]) && $r[$i] !== '') {
                    $valores[] = $r[$i];
                }
            }
            if ($valores === []) {
                continue;
            }
            $ok = 0;
            foreach ($valores as $v) {
                if (preg_match('/^[\d.,:%hH\s:]+$/u', $v) && preg_match('/\d/', $v)) {
                    $ok++;
                }
            }
            if ($ok >= max(1, (int) ceil(count($valores) * 0.6))) {
                $out[] = $i;
            }
        }

        return $out;
    }

    private function caminhoDestinoPdf(string $nomeAluno, string $model = 'questoes', ?string $id = null): string
    {
        $seguro = $this->sanitizarNomeArquivo($nomeAluno);
        $modelSeguro = preg_replace('/[^a-z0-9\-]/i', '', $model) ?: 'relatorio';
        $data = date('Ymd_Hi');
        // Formato: relatorio_{model}_{Ymd_Hi}_{aluno}_{periodo}.pdf
        $destino = $this->pastaDownload.'/relatorio_'.$modelSeguro.'_'.$data.'_'.$seguro.'_'.$this->periodo.'.pdf';
        $n = 1;
        while (file_exists($destino)) {
            $destino = $this->pastaDownload.'/relatorio_'.$modelSeguro.'_'.$data.'_'.$seguro.'_'.$this->periodo.'_'.$n.'.pdf';
            $n++;
        }

        return $destino;
    }

    private function sanitizarNomeArquivo(string $nomeAluno): string
    {
        // Mantém acentos (Giovanna, José, etc.); remove só caracteres inválidos para arquivo
        $seguro = preg_replace('/[^\p{L}\p{N} ._\\-]/u', '', $nomeAluno) ?? 'aluno';
        $seguro = preg_replace('/\s+/u', '_', trim($seguro)) ?? 'aluno';
        $seguro = preg_replace('/_+/u', '_', $seguro) ?? 'aluno';

        return trim($seguro, '._-') ?: 'aluno';
    }

    /**
     * Normaliza nome para comparação (sem acento/underscore/case).
     */
    private function normalizarParaComparacao(string $nome): string
    {
        $nome = mb_strtolower(str_replace('_', ' ', $nome));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        $nome = is_string($ascii) && $ascii !== '' ? $ascii : $nome;

        return preg_replace('/[^a-z0-9]+/', '', $nome) ?? '';
    }

    /**
     * Compara nomes do admin vs nome embutido no arquivo (aceita pequenas diferenças de digitação).
     */
    private function nomesArquivoSaoCompativeis(string $nomeAdmin, string $nomeArquivo): bool
    {
        if (strcasecmp($nomeAdmin, $nomeArquivo) === 0) {
            return true;
        }

        similar_text(mb_strtolower($nomeAdmin), mb_strtolower($nomeArquivo), $pct);
        if ($pct >= 85.0) {
            return true;
        }

        $a = $this->normalizarParaComparacao($nomeAdmin);
        $b = $this->normalizarParaComparacao($nomeArquivo);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        // Ex.: Larceda vs Lacerda (troca de letras)
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen <= 0) {
            return false;
        }
        $dist = levenshtein($a, $b);
        $limite = $maxLen <= 8 ? 1 : 2;

        return $dist >= 0 && $dist <= $limite;
    }

    /**
     * Extrai modelo e nome do aluno em filenames conhecidos.
     *
     * @return array{model: string, nome: string}|null
     */
    private function extrairMetaDoArquivoPdf(string $arquivo): ?array
    {
        $periodo = preg_quote($this->periodo, '/');

        // relatorio_{model}_{Ymd}_{Hi}_{Nome}_{periodo}.pdf
        if (preg_match('/^relatorio_([a-z0-9\-]+)_\d{8}_\d{4}_(.+)_'.$periodo.'(?:_\d+)?\.pdf$/ui', $arquivo, $m)) {
            return ['model' => strtolower($m[1]), 'nome' => $m[2]];
        }
        // legado: relatorio_{Ymd}_{Hi}_{Nome}_{periodo}.pdf (questões)
        if (preg_match('/^relatorio_\d{8}_\d{4}_(.+)_'.$periodo.'(?:_\d+)?\.pdf$/ui', $arquivo, $m)) {
            return ['model' => 'questoes', 'nome' => $m[1]];
        }
        // legado: relatorio-{id}-{Nome}-{ddmmyyyy}.pdf
        if (preg_match('/^relatorio-\d+-(.+)-\d{8}(?:_\d+)?\.pdf$/ui', $arquivo, $m)) {
            return ['model' => 'questoes', 'nome' => $m[1]];
        }
        // legado: {Nome}_{Ym}.pdf
        if (preg_match('/^(.+)_\d{4}-\d{2}(?:_\d+)?\.pdf$/ui', $arquivo, $m)) {
            return ['model' => 'questoes', 'nome' => $m[1]];
        }

        return null;
    }

    /**
     * Localiza o PDF consolidado mais recente do aluno na pasta de download.
     *
     * @return list<string>
     */
    private function encontrarPdfsAluno(string $nomeAluno): array
    {
        if (! is_dir($this->pastaDownload)) {
            return [];
        }

        $seguro = $this->sanitizarNomeArquivo($nomeAluno);
        $candidatos = [];

        foreach (scandir($this->pastaDownload) ?: [] as $arquivo) {
            if (! str_ends_with(mb_strtolower($arquivo), '.pdf')) {
                continue;
            }
            $meta = $this->extrairMetaDoArquivoPdf($arquivo);
            if ($meta === null) {
                continue;
            }
            if ($meta['model'] !== 'consolidado') {
                continue;
            }
            if (! $this->nomesArquivoSaoCompativeis($seguro, $meta['nome'])) {
                continue;
            }
            $candidatos[] = $this->pastaDownload.'/'.$arquivo;
        }

        if ($candidatos === []) {
            return [];
        }

        usort($candidatos, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return [$candidatos[0]];
    }

    /**
     * Cadastro local só vira "PDF não localizado" se o aluno estava na lista
     * de ativos da Tutory (ou nas gerações desta execução).
     */
    private function constaComoAtivoNaTutory(Aluno $aluno): bool
    {
        $idLocal = trim((string) $aluno->tutory_id);
        $nomeLocal = $this->sanitizarNomeArquivo($aluno->nome);

        foreach ($this->alunosAtivosTutory as $ativo) {
            if ($idLocal !== '' && $idLocal === trim((string) $ativo['id'])) {
                return true;
            }
            if ($this->nomesArquivoSaoCompativeis($nomeLocal, $this->sanitizarNomeArquivo($ativo['nome']))) {
                return true;
            }
        }

        if ($this->resumoExecucao !== null) {
            foreach ($this->resumoExecucao->geracoes as $geracao) {
                $nome = (string) ($geracao['nome'] ?? '');
                if ($nome !== '' && $this->nomesArquivoSaoCompativeis($nomeLocal, $this->sanitizarNomeArquivo($nome))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lista alunos do admin, envia os PDFs por e-mail e apaga os arquivos da pasta.
     * Se o SQLite local não existir, os PDFs são preservados.
     */
    private function enviarEmailsDosAlunos(): void
    {
        try {
            $this->enviarEmailsDosAlunosSemLimpar();
        } catch (QueryException|SQLiteDatabaseDoesNotExistException $exc) {
            if ($this->resumoExecucao !== null) {
                $this->resumoExecucao->bancoIndisponivel = true;
            }
            $this->log('Banco local indisponível; e-mails não enviados.');
            $this->log($exc->getMessage());
            $this->log('PDFs preservados em '.$this->pastaDownload);
            $this->log('No servidor: touch database/database.sqlite && php artisan migrate --force');

            return;
        }

        $this->removerPdfsBaixados();
    }

    /**
     * Lista alunos do admin e envia os PDFs por e-mail (todos os anexos em um único e-mail).
     */
    private function enviarEmailsDosAlunosSemLimpar(): void
    {
        $this->log(str_repeat('=', 50));
        $this->log('Enviando relatórios por e-mail (alunos cadastrados no admin)...');

        $query = Aluno::query()->orderBy('nome');
        $alunos = $query->get();
        if ($this->teste) {
            $alvo = mb_strtolower(self::ALUNO_TESTE);
            $alunos = $alunos
                ->filter(static fn (Aluno $a) => str_contains(mb_strtolower($a->nome), $alvo))
                ->values();
        }

        if ($alunos->isEmpty()) {
            $this->log('Nenhum aluno cadastrado no admin para envio.');

            return;
        }

        [$dtIni, $dtFim] = $this->datasPeriodoBr();
        $periodoLabel = $dtIni.' a '.$dtFim.' (período '.$this->periodo.')';

        $enviados = 0;
        $boasVindas = 0;
        $pulados = 0;
        $falhas = 0;

        foreach ($alunos as $aluno) {
            $this->log("[{$aluno->nome}] e-mail={$aluno->email} | recebe_email=".($aluno->recebe_email ? 'sim' : 'não'));

            if (! $aluno->ativo) {
                $this->log("[{$aluno->nome}] Aluno inativo no admin; e-mail não tentado.");

                continue;
            }

            if ($aluno->ehNovato()) {
                $this->enviarBoasVindasNovato($aluno, $boasVindas, $pulados, $falhas);

                continue;
            }

            $pdfs = $this->encontrarPdfsAluno($aluno->nome);
            if ($pdfs === []) {
                if (! $this->constaComoAtivoNaTutory($aluno)) {
                    $this->log("[{$aluno->nome}] Cadastro local fora dos ativos da Tutory nesta execução. Sem PDF, e-mail não tentado.");

                    continue;
                }
                $this->log("[{$aluno->nome}] Nenhum PDF encontrado em {$this->pastaDownload}");
                $this->log("[{$aluno->nome}] Dica: o nome no admin deve coincidir com o do Tutory.");
                $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::SEM_PDF);
                $falhas++;

                continue;
            }

            $nomesAnexos = [];
            foreach ($pdfs as $pdf) {
                $this->log("[{$aluno->nome}] PDF: ".basename($pdf));
                $nomesAnexos[] = 'Relatório consolidado';
            }

            $chavePeriodo = $this->mesDoPeriodo()->format('Y-m').'-'.$this->periodo;
            $avaliacao = $this->avaliarDesempenhoDosPdfs($aluno, $pdfs, $chavePeriodo);
            $blocos = $avaliacao['blocos'] ?? [];
            $resumo = $avaliacao['resumo'] ?? null;
            if ($blocos !== []) {
                $this->log("[{$aluno->nome}] Desempenho: ".count($blocos).' bloco(s) — '.($resumo ?? 'ok'));
            } else {
                $this->log("[{$aluno->nome}] Desempenho: sem blocos (métricas ausentes ou parâmetros não seedados)");
            }

            $aluno->aplicarAvaliacaoDesempenho($avaliacao, $chavePeriodo);
            $this->log(
                "[{$aluno->nome}] métricas atualizadas:"
                .' constância='.($aluno->last_performance ?: '—')
                .' | questions='.($aluno->last_question_volume ?: '—')
                .' | accuracy='.($aluno->last_accuracy_rate ?: '—')
                .' | subjects='.($aluno->last_subjects ?: '—')
            );

            if (! $aluno->recebe_email) {
                $this->log("[{$aluno->nome}] E-mail não enviado (recebe_email=false)");
                $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::PULADO);
                $pulados++;

                continue;
            }

            if (! filter_var($aluno->email, FILTER_VALIDATE_EMAIL)) {
                $this->log("[{$aluno->nome}] E-mail inválido: {$aluno->email}");
                $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::INVALIDO);
                $falhas++;

                continue;
            }

            try {
                MailHelper::emailRelatorioCoach(
                    [
                        'nome' => $aluno->nome,
                        'periodoLabel' => $periodoLabel,
                        'relatorios' => $nomesAnexos !== [] ? $nomesAnexos : ['Relatório consolidado'],
                        'nivelDesempenho' => $resumo,
                        'textoDesempenho' => null,
                        'blocosDesempenho' => $blocos,
                        'metricasDesempenho' => $avaliacao['metricas'] ?? [],
                    ],
                    $aluno->email,
                    $pdfs
                );
                $this->log("[{$aluno->nome}] E-mail enviado para {$aluno->email} com ".count($pdfs).' anexo(s)'.$this->sufixoCco($aluno->email));
                $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::ENVIADO);
                $enviados++;
            } catch (Throwable $exc) {
                $falhas++;
                $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::FALHA);
                $this->log("[{$aluno->nome}] Falha ao enviar e-mail: ".$exc->getMessage());
                Log::warning('Falha ao enviar relatório do coach', [
                    'aluno_id' => $aluno->id,
                    'email' => $aluno->email,
                    'erro' => $exc->getMessage(),
                ]);
            }
        }

        $this->log(str_repeat('=', 50));
        $this->log("E-mails enviados: {$enviados} | boas-vindas: {$boasVindas} | pulados: {$pulados} | falhas: {$falhas}");
    }

    private function enviarBoasVindasNovato(Aluno $aluno, int &$boasVindas, int &$pulados, int &$falhas): void
    {
        $this->log("[{$aluno->nome}] Aluno novato: métricas não analisadas e PDF não enviado.");

        if (! $aluno->recebe_email) {
            $this->log("[{$aluno->nome}] Boas-vindas não enviadas (recebe_email=false)");
            $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::PULADO);
            $this->registrarNovato($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::PULADO);
            $pulados++;

            return;
        }

        if (! filter_var($aluno->email, FILTER_VALIDATE_EMAIL)) {
            $this->log("[{$aluno->nome}] E-mail inválido: {$aluno->email}");
            $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::INVALIDO);
            $this->registrarNovato($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::INVALIDO);
            $falhas++;

            return;
        }

        try {
            MailHelper::emailBoasVindasNovato(
                TextoBoasVindasNovato::paraAluno($aluno),
                $aluno->email
            );
            $this->log("[{$aluno->nome}] Boas-vindas enviadas para {$aluno->email}".$this->sufixoCco($aluno->email));
            $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::BOAS_VINDAS);
            $this->registrarNovato($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::BOAS_VINDAS);
            $boasVindas++;
        } catch (Throwable $exc) {
            $falhas++;
            $this->registrarEnvio($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::FALHA);
            $this->registrarNovato($aluno->nome, (string) $aluno->email, ResumoExecucaoRelatorio::FALHA);
            $this->log("[{$aluno->nome}] Falha ao enviar boas-vindas: ".$exc->getMessage());
            Log::warning('Falha ao enviar boas-vindas de aluno novato', [
                'aluno_id' => $aluno->id,
                'email' => $aluno->email,
                'erro' => $exc->getMessage(),
            ]);
        }
    }

    private function sufixoCco(string $destinatario): string
    {
        $cco = MailHelper::enderecosCco($destinatario);
        if ($cco === []) {
            return '';
        }

        return ' (cco: '.implode(', ', $cco).')';
    }

    /**
     * Remove todos os PDFs da pasta de download (e os .metricas.json ao lado).
     * Os anexos já foram lidos pelo Mailer no envio.
     */
    private function removerPdfsBaixados(): void
    {
        if (! is_dir($this->pastaDownload)) {
            return;
        }

        $removidos = 0;
        foreach (scandir($this->pastaDownload) ?: [] as $arquivo) {
            if ($arquivo === '.' || $arquivo === '..') {
                continue;
            }
            if (! str_ends_with(mb_strtolower($arquivo), '.pdf')) {
                continue;
            }
            $caminho = $this->pastaDownload.'/'.$arquivo;
            if (! is_file($caminho)) {
                continue;
            }
            $sidecar = $this->caminhoMetricasSidecar($caminho);
            if (@unlink($caminho)) {
                $removidos++;
                $this->log('PDF removido: '.$arquivo);
            } else {
                $this->log('Não foi possível remover: '.$arquivo);

                continue;
            }
            if (is_file($sidecar) && @unlink($sidecar)) {
                $this->log('Métricas removidas: '.basename($sidecar));
            }
        }

        if ($this->resumoExecucao !== null) {
            $this->resumoExecucao->pdfsRemovidos = $removidos;
        }

        $this->log($removidos === 0
            ? 'Nenhum PDF restante para remover em '.$this->pastaDownload
            : "PDFs removidos após o envio: {$removidos}");
    }

    private function registrarEnvio(string $nome, string $email, string $situacao): void
    {
        if ($this->resumoExecucao === null) {
            return;
        }

        $this->resumoExecucao->envios[] = [
            'nome' => $nome,
            'email' => $email,
            'situacao' => $situacao,
        ];
    }

    private function registrarNovato(string $nome, string $email = '', string $situacao = ''): void
    {
        $this->resumoExecucao?->registrarNovato($nome, $email, $situacao);
    }

    /**
     * Fallback Dompdf para o modelo progresso (quando Node/Puppeteer falha).
     */
    private function gerarPdfProgressoDoHtml(
        string $nome,
        string $id,
        string $html,
        string $dtIniIso,
        string $dtFimIso,
    ): ?string {
        $this->log("[{$nome}] Montando PDF de Progresso do plano (Dompdf)...");

        $xp = $this->loadDom($html);
        $periodo = $this->xpathText($xp, "//*[contains(@class,'report-header')]//p")
            ?: "Período do relatório: de {$dtIniIso} a {$dtFimIso}";
        $periodo = PdfDatas::textoParaBr($periodo);
        $curso = $this->xpathText($xp, "//*[contains(@class,'report-aluno-desc')]//p")
            ?: '';

        $panoramaHtml = $this->montarHtmlPanoramaProgresso($xp);
        $motivacaoHtml = $this->montarHtmlMotivacaoProgresso($xp);
        $perguntasHtml = $this->montarHtmlPerguntasProgresso($xp);
        $assuntosHtml = $this->montarHtmlAssuntos($xp);

        // Mesmos gráficos do PDFWriter.start() do painel (ordem das páginas oficiais)
        $chartPrincipal = $this->chartImgHtml($html, 'chart_progresso_principal', null);
        $chartModalidades = $this->chartImgHtml($html, 'chart_progresso_modalidades', null);
        $chartTop = $this->chartImgHtml($html, 'chart_top_disciplinas', null);
        $chartPizzaMod = $this->chartImgHtml($html, 'chart_pizza_modalidades', null);
        $chartHoras = $this->chartImgHtml($html, 'chart_horas_diarias', null);
        $chartTx = $this->chartImgHtml($html, 'chart_tx_acerto', null);
        $chartBar = $this->chartImgHtml($html, 'chart_bar_questoes_disciplina', null);
        $chartPizzaQ = $this->chartImgHtml($html, 'chart_pizza_questoes', null);
        $chartLinha = $this->chartImgHtml($html, 'chart_linha_evolucao_questoes', null);
        $chartEstudo = $this->chartImgHtml($html, 'chart_progresso_estudo', null);
        $chartResumo = $this->chartImgHtml($html, 'chart_progresso_resumo', null);
        $chartRevisao = $this->chartImgHtml($html, 'chart_progresso_revisao', null);
        $chartExercicio = $this->chartImgHtml($html, 'chart_progresso_exercicio', null);

        $desc1 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-1')]/following-sibling::p[1]")
            ?: 'Confira o seu progresso geral no plano de estudos', ENT_QUOTES, 'UTF-8');
        $desc1b = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-1-1')]")
            ?: 'Confira a seguir, o progresso por modalidade de estudo:', ENT_QUOTES, 'UTF-8');
        $desc2 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-2')]/following-sibling::p[1]")
            ?: 'Confira as disciplinas com que você mais teve contato no período', ENT_QUOTES, 'UTF-8');
        $desc2b = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-2-1')]")
            ?: 'Esse é o panorama das modalidades de estudo praticadas', ENT_QUOTES, 'UTF-8');
        $desc3 = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-3-1')]")
            ?: 'Agora, vamos comparar as horas por dia com o seu horário atual', ENT_QUOTES, 'UTF-8');
        $desc4 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-4')]/following-sibling::p[1]")
            ?: 'Confira o histórico da sua taxa de acerto de questões', ENT_QUOTES, 'UTF-8');
        $desc4b = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-4-1')]")
            ?: 'Aqui estão as matérias em que você vai bem ou precisa melhorar', ENT_QUOTES, 'UTF-8');
        $desc4c = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-4-2')]")
            ?: 'Esse aqui é o panorama de todas as disciplinas que você praticou', ENT_QUOTES, 'UTF-8');
        $desc4d = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-4-3')]")
            ?: 'Agora, vamos conferir quais disciplinas estão sendo mais praticadas', ENT_QUOTES, 'UTF-8');
        $desc4e = htmlspecialchars($this->xpathText($xp, "//*[contains(@class,'section-4-4')]")
            ?: 'Por fim, vamos analisar a evolução do seu desempenho por disciplina', ENT_QUOTES, 'UTF-8');
        $desc5 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-5')]/following-sibling::p[1]")
            ?: 'Confira o seu progresso por modalidade de estudo', ENT_QUOTES, 'UTF-8');
        $desc6 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-6')]/following-sibling::p[1]")
            ?: 'Confira o seu progresso por modalidade de resumo', ENT_QUOTES, 'UTF-8');
        $desc7 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-7')]/following-sibling::p[1]")
            ?: 'Confira o seu progresso por modalidade de revisão', ENT_QUOTES, 'UTF-8');
        $desc8 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-8')]/following-sibling::p[1]")
            ?: 'Confira o seu progresso por modalidade de exercício', ENT_QUOTES, 'UTF-8');
        $desc9 = htmlspecialchars($this->xpathText($xp, "//h2[contains(@class,'section-9')]/following-sibling::p[1]")
            ?: 'Para finalizar, segue o seu histórico de metas cumpridas neste período:', ENT_QUOTES, 'UTF-8');

        $seguroNome = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $seguroCurso = htmlspecialchars($curso, ENT_QUOTES, 'UTF-8');
        $seguroPeriodo = htmlspecialchars($periodo, ENT_QUOTES, 'UTF-8');
        $logoHtml = $this->montarHtmlLogoPdf();
        $page = $this->htmlQuebraPaginaComLogo($logoHtml);
        $pieCss = $this->cssChartPie();
        $fontCss = $this->cssFamiliaFontePdf();
        $blocoEstudo = $this->htmlPaginaModalidade($page, $desc5, $chartEstudo);
        $blocoResumo = $this->htmlPaginaModalidade($page, $desc6, $chartResumo);
        $blocoRevisao = $this->htmlPaginaModalidade($page, $desc7, $chartRevisao);
        $blocoExercicio = $this->htmlPaginaModalidade($page, $desc8, $chartExercicio);

        $pdfHtml = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family: {$fontCss}; font-size:12px; color:#222; margin:24px;}
.logo{text-align:center; margin:0 0 14px; color:#000; font-family: {$fontCss}; font-weight:bold; line-height:0.95;}
.logo .l1{font-size:18pt; letter-spacing:2pt;}
.logo .l2{font-size:16pt; letter-spacing:1pt;}
h1{font-size:20px; margin:0 0 6px; text-align:center;}
.periodo{color:#555; margin-bottom:14px; text-align:center;}
.aluno{margin:10px 0 18px;}
.aluno h2{margin:0 0 4px; font-size:16px;}
.section{margin:22px 0 8px; padding-left:10px; border-left:4px solid #00aced; font-size:15px;}
.section-desc{color:#555; margin:0 0 12px;}
.panorama{width:100%; border-collapse:separate; border-spacing:8px 0; margin:6px 0 14px; table-layout:fixed;}
.panorama td{
  border:0.4pt solid #cdcdcd;
  padding:6pt 8pt 10pt;
  vertical-align:top;
  background:#ffffff;
}
.panorama .label{color:#888; font-size:8pt; margin:0 0 8pt; line-height:1.1;}
.panorama .value{font-size:11pt; font-weight:bold; color:#000000; margin:0; line-height:1.1;}
.chart{width:100%; max-width:700px; max-height:280px; margin:8px 0 14px;}
.chart-sm{width:100%; max-width:700px; max-height:140px; margin:8px 0 14px;}
.chart-mod{width:640px; height:280px; margin:8px 0 12px; display:block; page-break-inside:avoid;}
.keep{page-break-inside:avoid;}
.keep .section{margin-top:8px;}
{$pieCss}
.assuntos{width:100%; border-collapse:collapse; margin-top:8px; font-size:11px;}
.assuntos thead td{background:#00aced; color:#fff; font-weight:bold; padding:8px;}
.assuntos tbody td{border-bottom:1px solid #eee; padding:8px; vertical-align:top;}
.assuntos .taxa{text-align:right; font-weight:bold;}
.motivacao p{margin:0 0 8px; line-height:1.4;}
.questions{width:100%; border-collapse:collapse; margin:8px 0 16px;}
.questions td{width:50%; text-align:center; vertical-align:top; padding:8px;}
.rule{border:0;border-top:1px solid #eaeaea; margin:12px 0;}
</style></head><body>
{$logoHtml}
<h1>Progresso no Plano</h1>
<div class="periodo">{$seguroPeriodo}</div>
<hr class="rule" />
<div class="aluno">
  <h2>{$seguroNome}</h2>
  <div class="periodo" style="text-align:left;margin:0;">{$seguroCurso}</div>
</div>

<h2 class="section">Progresso no Plano</h2>
<p class="section-desc">{$desc1}</p>
{$chartPrincipal}
{$panoramaHtml}
<p class="section-desc">{$desc1b}</p>
{$chartModalidades}

{$page}
<h2 class="section">Panorama</h2>
<p class="section-desc">{$desc2}</p>
{$chartTop}
<p class="section-desc">{$desc2b}</p>
{$chartPizzaMod}

{$page}
<h2 class="section">Motivação</h2>
<p class="section-desc">{$desc3}</p>
{$chartHoras}
<div class="motivacao">{$motivacaoHtml}</div>

{$page}
<h2 class="section">Desempenho de Questões</h2>
<p class="section-desc">{$desc4}</p>
{$chartTx}
<p class="section-desc">{$desc4b}</p>
{$perguntasHtml}
<p class="section-desc">{$desc4c}</p>
{$chartBar}

{$page}
<p class="section-desc">{$desc4d}</p>
{$chartPizzaQ}
<p class="section-desc">{$desc4e}</p>
{$chartLinha}

{$blocoEstudo}
{$blocoResumo}
{$blocoRevisao}
{$blocoExercicio}

{$page}
<h2 class="section">Desempenho de Questões</h2>
<p class="section-desc">{$desc9}</p>
{$assuntosHtml}
</body></html>
HTML;

        try {
            $dompdf = new Dompdf($this->opcoesDompdf());
            $dompdf->loadHtml($pdfHtml, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $this->salvarBytesPdf($nome, $dompdf->output() ?? '', 'progresso', $id);
        } catch (Throwable $exc) {
            $this->log("[{$nome}] Erro Dompdf (progresso): ".$exc->getMessage());

            return null;
        }
    }

    private function montarHtmlPanoramaProgresso(DOMXPath $xp): string
    {
        $rows = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' row-numbers ')]");
        if ($rows === false || $rows->length === 0) {
            return '<p style="color:#888;">(Panorama de progresso indisponível na página)</p>';
        }

        $cells = '';
        $primeiro = $rows->item(0);
        if (! $primeiro instanceof DOMElement) {
            return '<p style="color:#888;">(Panorama de progresso indisponível na página)</p>';
        }

        foreach ($primeiro->getElementsByTagName('div') as $col) {
            if (! $col instanceof DOMElement) {
                continue;
            }
            $class = ' '.$col->getAttribute('class').' ';
            if (! str_contains($class, ' col-')) {
                continue;
            }
            $h5 = '';
            $span = '';
            foreach ($col->getElementsByTagName('h5') as $el) {
                $h5 = trim((string) $el->textContent);
                break;
            }
            foreach ($col->getElementsByTagName('span') as $el) {
                $span = trim((string) $el->textContent);
                break;
            }
            if ($h5 === '' && $span === '') {
                continue;
            }
            $cells .= '<td><div class="label">'.htmlspecialchars($span, ENT_QUOTES, 'UTF-8').'</div>'
                .'<div class="value">'.htmlspecialchars($h5, ENT_QUOTES, 'UTF-8').'</div></td>';
        }

        if ($cells === '') {
            return '<p style="color:#888;">(Panorama de progresso vazio)</p>';
        }

        return '<table class="panorama"><tr>'.$cells.'</tr></table>';
    }

    /**
     * Constância a partir do gráfico de horas diárias do Progresso.
     *
     * @return array{dias_analisados: int, dias_estudados: int, dias_falhados: int}
     */
    public function extrairMetricasConstancia(string $html): array
    {
        $diasAnalisados = 0;
        $diasEstudados = 0;

        $cfg = $this->extrairChartConfig($html, 'chart_horas_diarias');
        if (is_array($cfg)) {
            $labels = $cfg['data']['labels'] ?? [];
            if (is_array($labels)) {
                $diasAnalisados = count($labels);
            }
            $datasets = $cfg['data']['datasets'] ?? [];
            if (is_array($datasets)) {
                foreach ($datasets as $ds) {
                    if (! is_array($ds)) {
                        continue;
                    }
                    $label = mb_strtolower((string) ($ds['label'] ?? ''));
                    if (! str_contains($label, 'estudad')) {
                        continue;
                    }
                    $series = $ds['data'] ?? [];
                    if (! is_array($series)) {
                        break;
                    }
                    foreach ($series as $valor) {
                        $n = (new AvaliadorDesempenho)->paraNumero($valor);
                        if ($n !== null && $n > 0) {
                            $diasEstudados++;
                        }
                    }
                    break;
                }
            }
        }

        // Fallback: tamanho do período do relatório
        if ($diasAnalisados <= 0) {
            [$ini, $fim] = $this->datasPeriodoIso();
            try {
                $d1 = new \DateTimeImmutable($ini);
                $d2 = new \DateTimeImmutable($fim);
                $diasAnalisados = max(1, (int) $d1->diff($d2)->days + 1);
            } catch (Throwable) {
                $diasAnalisados = 15;
            }
        }

        $diasFalhados = max(0, $diasAnalisados - $diasEstudados);

        return [
            'dias_analisados' => $diasAnalisados,
            'dias_estudados' => $diasEstudados,
            'dias_falhados' => $diasFalhados,
        ];
    }

    /**
     * Volume, % geral e assuntos a partir do relatório de Questões.
     *
     * @return array{total_questoes: float|null, percentual_acertos: float|null, assuntos: list<array{disciplina: string, assunto: string, percentual: float|null}>}
     */
    public function extrairMetricasQuestoes(string $html): array
    {
        $avaliador = new AvaliadorDesempenho;
        $xp = $this->loadDom($html);
        $total = null;
        $pct = null;

        $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' main-numbers ')]");
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $label = '';
                $valor = '';
                foreach ($node->getElementsByTagName('p') as $el) {
                    $label = trim((string) $el->textContent);
                    break;
                }
                foreach ($node->getElementsByTagName('h3') as $el) {
                    $valor = trim((string) $el->textContent);
                    break;
                }
                $key = $this->normalizarParaComparacao($label);
                if ($key === 'questoes') {
                    $total = $avaliador->paraNumero($valor);
                } elseif (str_contains($key, 'acerto')) {
                    $pct = $avaliador->paraNumero($valor);
                }
            }
        }

        $assuntos = [];
        $tabela = $xp->query("//table[@id='tabela_questoes']//tbody/tr");
        if ($tabela !== false) {
            foreach ($tabela as $tr) {
                if (! $tr instanceof DOMElement) {
                    continue;
                }
                $tds = [];
                foreach ($tr->getElementsByTagName('td') as $td) {
                    $tds[] = trim(preg_replace('/\s+/', ' ', (string) $td->textContent) ?? '');
                }
                if (count($tds) < 3) {
                    continue;
                }
                $assuntos[] = [
                    'disciplina' => $tds[0],
                    'assunto' => $tds[1],
                    'percentual' => $avaliador->paraNumero($tds[2]),
                ];
            }
        }

        return [
            'total_questoes' => $total,
            'percentual_acertos' => $pct,
            'assuntos' => $assuntos,
        ];
    }

    private function salvarMetricasRelatorioSeAplicavel(string $pdfPath, string $model, string $html): void
    {
        if ($pdfPath === '' || ! is_file($pdfPath)) {
            return;
        }

        $metricas = match ($model) {
            'progresso' => $this->extrairMetricasConstancia($html),
            'questoes' => $this->extrairMetricasQuestoes($html),
            default => null,
        };
        if ($metricas === null) {
            return;
        }

        $sidecar = $this->caminhoMetricasSidecar($pdfPath);
        $payload = [
            'gerado_em' => date('c'),
            'periodo' => $this->periodo,
            'model' => $model,
            'metricas' => $metricas,
        ];
        file_put_contents($sidecar, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->log("Métricas de desempenho ({$model}) salvas: ".$sidecar);
    }

    private function caminhoMetricasSidecar(string $pdfPath): string
    {
        if (str_ends_with(strtolower($pdfPath), '.pdf')) {
            return substr($pdfPath, 0, -4).'.metricas.json';
        }

        return $pdfPath.'.metricas.json';
    }

    /**
     * @param  list<string>  $pdfs
     * @return array{blocos: list<array<string, mixed>>, metricas: array<string, mixed>, resumo: string|null}
     */
    private function avaliarDesempenhoDosPdfs(Aluno $aluno, array $pdfs, string $periodo): array
    {
        $avaliador = new AvaliadorDesempenho;
        $dados = ['nome' => $aluno->nome];

        foreach ($pdfs as $pdf) {
            $meta = $this->extrairMetaDoArquivoPdf(basename($pdf));
            if ($meta === null) {
                continue;
            }
            $sidecar = $this->caminhoMetricasSidecar($pdf);
            if (! is_file($sidecar)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($sidecar), true);
            if (! is_array($json) || ! is_array($json['metricas'] ?? null)) {
                continue;
            }
            $model = (string) ($meta['model'] ?? $json['model'] ?? '');
            $m = $json['metricas'];
            if ($model === 'consolidado') {
                $dados['dias_analisados'] = $m['dias_analisados'] ?? null;
                $dados['dias_estudados'] = $m['dias_estudados'] ?? null;
                $dados['dias_falhados'] = $m['dias_falhados'] ?? null;
                $dados['total_questoes'] = $m['total_questoes'] ?? null;
                $dados['percentual_acertos'] = $m['percentual_acertos'] ?? null;
                $dados['assuntos'] = is_array($m['assuntos'] ?? null) ? $m['assuntos'] : [];
            } elseif ($model === 'progresso') {
                $dados['dias_analisados'] = $m['dias_analisados'] ?? null;
                $dados['dias_estudados'] = $m['dias_estudados'] ?? null;
                $dados['dias_falhados'] = $m['dias_falhados'] ?? null;
            } elseif ($model === 'questoes') {
                $dados['total_questoes'] = $m['total_questoes'] ?? null;
                $dados['percentual_acertos'] = $m['percentual_acertos'] ?? null;
                $dados['assuntos'] = is_array($m['assuntos'] ?? null) ? $m['assuntos'] : [];
            }
        }

        return $avaliador->avaliarRelatorio($dados, $aluno, $periodo);
    }

    /**
     * Painel de Insights: textos originais, layout escaneável, sem título duplicado.
     */
    private function montarHtmlInsights(DOMXPath $xp): string
    {
        $paragrafos = $this->textosInsights($xp);
        if ($paragrafos === []) {
            return '';
        }

        return RelatorioConsolidadoLayout::insights($paragrafos);
    }

    /**
     * @return list<string>
     */
    private function textosInsights(DOMXPath $xp): array
    {
        $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' insights-panel ')]//p");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xp->query("//h2[contains(.,'Motiv')]/following-sibling::p[position()<=8]");
        }
        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $paragrafos = [];
        foreach ($nodes as $node) {
            $txt = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($txt !== '') {
                $paragrafos[] = $txt;
            }
        }

        return $paragrafos;
    }

    private function montarHtmlMotivacaoProgresso(DOMXPath $xp): string
    {
        return $this->montarHtmlInsights($xp);
    }

    /**
     * Melhor/pior disciplina (bloco row-questions do relatório de progresso).
     */
    private function montarHtmlPerguntasProgresso(DOMXPath $xp): string
    {
        $cols = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' row-questions ')]//*[contains(@class,'col-')]");
        if ($cols === false || $cols->length === 0) {
            return '';
        }

        $cells = '';
        foreach ($cols as $col) {
            if (! $col instanceof DOMElement) {
                continue;
            }
            $txt = trim(preg_replace('/\s+/', ' ', (string) $col->textContent) ?? '');
            if ($txt === '') {
                continue;
            }
            $cells .= '<td>'.nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')).'</td>';
        }

        return $cells !== '' ? '<table class="questions"><tr>'.$cells.'</tr></table>' : '';
    }

    private function gerarPdfDoHtml(
        string $nome,
        string $id,
        string $html,
        string $dtIniIso,
        string $dtFimIso,
        string $model = 'questoes',
    ): ?string {
        $this->log("[{$nome}] Montando PDF (Dompdf: panorama + gráficos + assuntos)...");

        $xp = $this->loadDom($html);
        $periodo = $this->xpathText($xp, "//*[contains(@class,'report-header')]//p")
            ?: "Período do relatório: de {$dtIniIso} a {$dtFimIso}";
        $periodo = PdfDatas::textoParaBr($periodo);
        $curso = $this->xpathText($xp, "//*[contains(@class,'report-aluno-desc')]//p")
            ?: '';

        $panoramaHtml = $this->montarHtmlPanorama($xp);
        $assuntosHtml = $this->montarHtmlAssuntos($xp);

        $chartDia = $this->chartImgHtml($html, 'chart_questoes_dia', 'Acertos e Erros por Dia');
        $chartBolha = $this->chartImgHtml($html, 'chart_bolha_questoes', null);
        $chartMelhores = $this->chartImgHtml($html, 'chart_top_melhores', null);
        $chartPiores = $this->chartImgHtml($html, 'chart_top_piores', null);
        $chartEvolucao = $this->chartImgHtml($html, 'chart_evolucao_materia', null);

        $sec2Desc = $this->xpathText($xp, "//h2[contains(@class,'section-2')]/following-sibling::p[1]")
            ?: 'Confira o seu desempenho por disciplinas';
        $sec3Desc = $this->xpathText($xp, "//h2[contains(@class,'section-3')]/following-sibling::p[1]")
            ?: 'Agora, vamos acompanhar sua evolução no tempo por matéria';
        $melhoresTitulo = $this->xpathText($xp, "//*[contains(@class,'section-2-1')]") ?: 'Suas 3 melhores matérias';
        $pioresTitulo = $this->xpathText($xp, "//*[contains(@class,'section-2-2')]") ?: 'Suas 3 piores matérias';

        $seguroNome = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $seguroCurso = htmlspecialchars($curso, ENT_QUOTES, 'UTF-8');
        $seguroPeriodo = htmlspecialchars($periodo, ENT_QUOTES, 'UTF-8');
        $seguroSec2 = htmlspecialchars($sec2Desc, ENT_QUOTES, 'UTF-8');
        $seguroSec3 = htmlspecialchars($sec3Desc, ENT_QUOTES, 'UTF-8');
        $seguroMelhores = htmlspecialchars($melhoresTitulo, ENT_QUOTES, 'UTF-8');
        $seguroPiores = htmlspecialchars($pioresTitulo, ENT_QUOTES, 'UTF-8');
        $logoHtml = $this->montarHtmlLogoPdf();
        $page = $this->htmlQuebraPaginaComLogo($logoHtml);
        $pieCss = $this->cssChartPie();
        $fontCss = $this->cssFamiliaFontePdf();

        $pdfHtml = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family: {$fontCss}; font-size:12px; color:#222; margin:24px;}
.logo{text-align:center; margin:0 0 14px; color:#000; font-family: {$fontCss}; font-weight:bold; line-height:0.95;}
.logo .l1{font-size:18pt; letter-spacing:2pt;}
.logo .l2{font-size:16pt; letter-spacing:1pt;}
h1{font-size:20px; margin:0 0 6px; text-align:center;}
.periodo{color:#555; margin-bottom:14px; text-align:center;}
.aluno{margin:10px 0 18px;}
.aluno h2{margin:0 0 4px; font-size:16px;}
.section{margin:22px 0 8px; padding-left:10px; border-left:4px solid #00aced; font-size:15px;}
.section-desc{color:#555; margin:0 0 12px;}
.panorama{width:100%; border-collapse:separate; border-spacing:10px 0; margin:6px 0 14px; table-layout:fixed;}
.panorama td{
  width:33%;
  border:0.4pt solid #cdcdcd;
  padding:6pt 8pt 10pt;
  height:52pt;
  vertical-align:top;
  background:#ffffff;
}
.panorama .label{color:#cccccc; font-size:9pt; margin:0 0 12pt; line-height:1.1;}
.panorama .value{font-size:12pt; font-weight:bold; color:#000000; margin:0; line-height:1.1;}
.chart{width:100%; max-width:700px; max-height:280px; margin:8px 0 14px;}
.chart-sm{width:100%; max-width:700px; max-height:140px; margin:8px 0 14px;}
{$pieCss}
.two-col{width:100%; border-collapse:collapse;}
.two-col td{width:50%; vertical-align:top; padding:4px;}
.assuntos{width:100%; border-collapse:collapse; margin-top:8px; font-size:11px;}
.assuntos thead td{background:#00aced; color:#fff; font-weight:bold; padding:8px;}
.assuntos tbody td{border-bottom:1px solid #eee; padding:8px; vertical-align:top;}
.assuntos .taxa{text-align:right; font-weight:bold;}
.rule{border:0;border-top:1px solid #eaeaea; margin:12px 0;}
</style></head><body>
{$logoHtml}
<h1>Relatório de Questões</h1>
<div class="periodo">{$seguroPeriodo}</div>
<hr class="rule" />
<div class="aluno">
  <h2>{$seguroNome}</h2>
  <div class="periodo" style="text-align:left;margin:0;">{$seguroCurso}</div>
</div>

<h2 class="section">Breve Panorama</h2>
<p class="section-desc">Confira o seu desempenho de questões no período</p>
{$panoramaHtml}
{$chartDia}

{$page}
<h2 class="section">Desempenho por Disciplina</h2>
<p class="section-desc">{$seguroSec2}</p>
{$chartBolha}
<table class="two-col"><tr>
  <td><p><b>{$seguroMelhores}</b></p>{$chartMelhores}</td>
  <td><p><b>{$seguroPiores}</b></p>{$chartPiores}</td>
</tr></table>

{$page}
<h2 class="section">Evolução do Desempenho por Disciplina</h2>
<p class="section-desc">{$seguroSec3}</p>
{$chartEvolucao}

{$page}
<h2 class="section">Performance por assunto</h2>
<p class="section-desc">Confira o desempenho de questões por assunto no período do relatório:</p>
{$assuntosHtml}
</body></html>
HTML;

        try {
            $dompdf = new Dompdf($this->opcoesDompdf());
            $dompdf->loadHtml($pdfHtml, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $this->salvarBytesPdf($nome, $dompdf->output() ?? '', $model, $id);
        } catch (Throwable $exc) {
            $this->log("[{$nome}] Erro Dompdf: ".$exc->getMessage());

            return null;
        }
    }

    /**
     * Uma página de Progresso por Modalidade: título + gráfico juntos (sem página vazia).
     */
    private function htmlPaginaModalidade(string $page, string $desc, string $chart): string
    {
        if (trim($chart) === '') {
            return '';
        }

        return $page
            .'<div class="keep">'
            .'<h2 class="section">Progresso por Modalidade</h2>'
            .'<p class="section-desc">'.$desc.'</p>'
            .$chart
            .'</div>';
    }

    /**
     * Quebra de página com a logo no topo (fluxo normal — evita overlap do position:fixed no Dompdf).
     */
    private function htmlQuebraPaginaComLogo(string $logoHtml): string
    {
        return '<div style="page-break-before: always;"></div>'.$logoHtml;
    }

    /**
     * Marca em texto no topo de cada página (sem imagem).
     */
    private function montarHtmlLogoPdf(): string
    {
        return '<div class="logo"><div class="l1">MISSÃO</div><div class="l2">NOMEAÇÃO</div></div>';
    }

    private function cssFamiliaFontePdf(): string
    {
        return PdfFontes::css();
    }

    private function opcoesDompdf(): Options
    {
        return PdfFontes::opcoesDompdf();
    }

    private function opcoesDompdfConsolidado(): Options
    {
        $options = PdfFontes::opcoesDompdf('inter');
        $options->set('defaultFont', 'DejaVu Sans');

        return $options;
    }

    private function montarHtmlPanorama(DOMXPath $xp): string
    {
        $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' main-numbers ')]");
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $items = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $label = '';
            $value = '';
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'p') {
                    $label = trim((string) $child->textContent);
                }
                if ($tag === 'h3') {
                    $value = trim((string) $child->textContent);
                }
            }
            if ($label === '' && $value === '') {
                continue;
            }
            $key = $this->normalizarParaComparacao($label);
            $destaque = str_contains($key, 'percent') || str_contains($key, '%') || (str_contains($key, 'acerto') && str_contains($value, '%'));
            $items[] = ['label' => $label, 'value' => $value, 'destaque' => $destaque];
        }

        return RelatorioConsolidadoLayout::cards($items);
    }

    private function montarHtmlAssuntos(DOMXPath $xp): string
    {
        $parsed = $this->extrairTabelaPorId($xp, 'tabela_questoes');
        if ($parsed === null || $parsed['rows'] === []) {
            return '';
        }
        $headers = $parsed['headers'] !== []
            ? $parsed['headers']
            : ['Disciplina', 'Assunto', 'Taxa de Acertos'];
        $percentCol = count($headers) - 1;
        $numeric = array_values(array_unique(array_merge($parsed['numeric'], [$percentCol])));

        return RelatorioConsolidadoLayout::tabela($headers, $parsed['rows'], [
            'numeric' => $numeric,
            'percent_col' => $percentCol,
        ]);
    }

    /**
     * CSS do consolidado Dompdf. Sem fundo cinza no body.
     * border-radius fica só nos cards (kpi), não no casco da página.
     */
    private function cssPdfConsolidado(string $fontCss, string $pieCss): string
    {
        return RelatorioConsolidadoLayout::css($fontCss, $pieCss, PdfFontes::fontFaceCss());
    }

    private function cssChartPie(): string
    {
        return sprintf(
            '.chart-pie{width:%dpx; height:%dpx; margin:8px auto 14px; display:block;}',
            self::PIE_PDF_WIDTH,
            self::PIE_PDF_HEIGHT
        );
    }

    private function chartImgHtml(string $html, string $chartId, ?string $titulo): string
    {
        if (! extension_loaded('gd')) {
            return $titulo
                ? '<p style="color:#888;">('.$titulo.' omitido: instale php-gd)</p>'
                : '';
        }
        $cfg = $this->extrairChartConfig($html, $chartId);
        if ($cfg === null) {
            return '';
        }
        $img = $this->quickChartPngDataUri($cfg);
        if ($img === null) {
            return '';
        }
        $out = '';
        if ($titulo !== null && $titulo !== '') {
            $out .= '<p style="text-align:center;font-weight:bold;margin:8px 0;">'
                .htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8').'</p>';
        }
        $type = (string) ($cfg['type'] ?? '');
        $isPie = in_array($type, ['pie', 'doughnut'], true);
        $isModalidade = in_array($chartId, self::CHARTS_MODALIDADE, true);
        $class = $isPie
            ? 'chart-pie'
            : ($isModalidade
                ? 'chart-mod'
                : (in_array($chartId, ['chart_progresso_principal'], true) ? 'chart-sm' : 'chart'));
        // Dimensões explícitas: Dompdf ignora max-height e parte a página
        if ($isPie) {
            $out .= '<img class="'.$class.'" src="'.$img.'" width="'.self::PIE_PDF_WIDTH
                .'" height="'.self::PIE_PDF_HEIGHT.'" />';
        } elseif ($isModalidade) {
            $out .= '<img class="'.$class.'" src="'.$img.'" width="'.self::MODALIDADE_PDF_WIDTH
                .'" height="'.self::MODALIDADE_PDF_HEIGHT.'" />';
        } else {
            $out .= '<img class="'.$class.'" src="'.$img.'" />';
        }

        return $out;
    }

    private function xpathText(DOMXPath $xp, string $query): string
    {
        $nodes = $xp->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent) ?? '');
    }

    /**
     * Extrai o objeto passado a `new Chart(el, { ... })` para o canvas informado.
     *
     * @return array<string, mixed>|null
     */
    private function extrairChartConfig(string $html, string $canvasId): ?array
    {
        // var elFoo = document.getElementById('chart_...');
        // new Chart(elFoo, { ... });
        $elVar = null;
        if (preg_match(
            '/var\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*document\.getElementById\(\s*[\'"]'.preg_quote($canvasId, '/').'[\'"]\s*\)/',
            $html,
            $vm
        )) {
            $elVar = $vm[1];
        }

        $jsonish = null;
        if ($elVar !== null) {
            $needle = 'new Chart('.$elVar;
            $pos = strpos($html, $needle);
            if ($pos === false) {
                $pos = strpos($html, 'new Chart( '.$elVar);
            }
            if ($pos !== false) {
                $slice = substr($html, $pos, 20000);
                if (preg_match('/new\s+Chart\s*\(\s*'.preg_quote($elVar, '/').'\s*,\s*(\{)/', $slice, $m, PREG_OFFSET_CAPTURE)) {
                    $jsonish = $this->extrairObjetoJsBalanceado($slice, (int) $m[1][1]);
                }
            }
        }

        // Fallback: new Chart(document.getElementById('id'), { ... })
        if ($jsonish === null) {
            if (preg_match(
                '/new\s+Chart\s*\(\s*document\.getElementById\(\s*[\'"]'.preg_quote($canvasId, '/').'[\'"]\s*\)\s*,\s*(\{)/',
                $html,
                $m,
                PREG_OFFSET_CAPTURE
            )) {
                $jsonish = $this->extrairObjetoJsBalanceado($html, (int) $m[1][1]);
            }
        }

        if ($jsonish === null) {
            return null;
        }

        $colors = $this->extrairChartColors($html);
        if ($colors === []) {
            $colors = ['#00ACED', '#FF595E', '#FFCA3A', '#8AC926', '#6A4C93'];
        }

        // JS object → JSON aproximado
        $json = $jsonish;
        // Substitui chartColors[Math.floor(Math.random()*chartColors.length)] por cores fixas
        $colorIdx = 0;
        $json = preg_replace_callback(
            '/chartColors\s*\[\s*Math\.floor\s*\(\s*Math\.random\s*\(\s*\)\s*\*\s*chartColors\.length\s*\)\s*\]/',
            static function () use (&$colorIdx, $colors): string {
                $c = $colors[$colorIdx % count($colors)];
                $colorIdx++;

                return "'".$c."'";
            },
            $json
        ) ?? $json;

        // Remove functions antes de expandir chartColors (evita lixo tipo chartColors[context.dataIndex])
        $json = preg_replace('/formatter\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/label\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/backgroundColor\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/[A-Za-z_][A-Za-z0-9_]*\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/function\s*\(.*?\)\s*\{.*?\},?/s', 'null,', $json) ?? $json;

        // Expandir chartColors[n] / chartColors[expr] restantes para cor fixa
        $json = preg_replace_callback(
            '/chartColors\s*\[\s*([^\]]+)\s*\]/',
            static function (array $m) use (&$colorIdx, $colors): string {
                $inner = trim($m[1]);
                if (ctype_digit($inner)) {
                    $c = $colors[((int) $inner) % count($colors)];
                } else {
                    $c = $colors[$colorIdx % count($colors)];
                    $colorIdx++;
                }

                return "'".$c."'";
            },
            $json
        ) ?? $json;

        // Expandir chartColors sem índice (ex.: backgroundColor: chartColors)
        $coresJson = json_encode(array_values($colors), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($coresJson === false) {
            $coresJson = '["#00ACED","#FF595E","#FFCA3A","#8AC926","#6A4C93"]';
        }
        $json = preg_replace('/\bchartColors\b/', $coresJson, $json) ?? $json;

        $json = preg_replace('/([{\[,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $json) ?? $json;
        $json = str_replace("'", '"', $json);
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;
        // remove functions residuais (já com aspas após quoting)
        $json = preg_replace('/"formatter"\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/"label"\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/"function"\s*:\s*function\s*\(.*?\)\s*\{.*?\},?/s', '', $json) ?? $json;
        $json = preg_replace('/function\s*\(.*?\)\s*\{.*?\},?/s', 'null,', $json) ?? $json;
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        $data = json_decode($json, true);
        if (! is_array($data)) {
            $this->log("Chart {$canvasId}: JSON inválido após normalização (".json_last_error_msg().')');

            return null;
        }

        $data = $this->normalizarCoresDataset($data);
        $data = $this->aplicarDatalabelsOficiais($data, $canvasId);
        $data = $this->aplicarIdentidadeVisualGrafico($data);
        if ($this->eGraficoBarrasAgrupadas($canvasId)) {
            $data = $this->aplicarBarrasAgrupadasInstitucionais($data, $canvasId);
        }

        return $data;
    }

    private function eGraficoBarrasAgrupadas(string $canvasId): bool
    {
        return in_array($canvasId, [
            'chart_line_comparativo',
            'chart_horas_diarias',
            'chart_questoes_dia',
        ], true);
    }

    private function eGraficoRitmo(string $canvasId): bool
    {
        return in_array($canvasId, ['chart_line_comparativo', 'chart_horas_diarias'], true);
    }

    /**
     * @return list<string> datas compactas d/m do período real
     */
    private function datasCompactasDoPeriodo(?\DateTimeInterface $ref = null): array
    {
        [$ini, $fim] = $this->datasPeriodoIso($ref);
        $d1 = new \DateTimeImmutable($ini);
        $d2 = new \DateTimeImmutable($fim);
        $out = [];
        for ($d = $d1; $d <= $d2; $d = $d->modify('+1 day')) {
            $out[] = $d->format('d/m');
        }

        return $out;
    }

    private function chaveDataRotulo(mixed $label): ?string
    {
        if (is_array($label)) {
            $label = implode(' ', array_map(static fn ($p): string => (string) $p, $label));
        }
        if (! is_scalar($label)) {
            return null;
        }
        $texto = PdfDatas::textoParaBr((string) $label);
        if (! preg_match('#(\d{1,2})/(\d{1,2})(?:/\d{2,4})?#', $texto, $m)) {
            return null;
        }

        return str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT)
            .'/'.str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Alinha séries às datas reais do período: não inventa zero, não descarta zero.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $datas
     * @return array<string, mixed>
     */
    private function alinharSeriesAsDatas(array $data, array $datas): array
    {
        if ($datas === [] || ! isset($data['data']['labels']) || ! is_array($data['data']['labels'])) {
            return $data;
        }

        $indicePorData = [];
        foreach ($data['data']['labels'] as $i => $label) {
            $chave = $this->chaveDataRotulo($label);
            if ($chave !== null) {
                $indicePorData[$chave] = $i;
            }
        }
        if ($indicePorData === []) {
            return $data;
        }

        $datasets = $data['data']['datasets'] ?? [];
        if (! is_array($datasets)) {
            return $data;
        }

        $novos = [];
        foreach ($datasets as $ds) {
            if (! is_array($ds)) {
                continue;
            }
            $serie = is_array($ds['data'] ?? null) ? $ds['data'] : [];
            $alinhada = [];
            foreach ($datas as $dia) {
                if (! isset($indicePorData[$dia])) {
                    $alinhada[] = null;

                    continue;
                }
                $valor = $serie[$indicePorData[$dia]] ?? null;
                $alinhada[] = $valor;
            }
            $ds['data'] = $alinhada;
            $novos[] = $ds;
        }

        $data['data']['labels'] = $datas;
        $data['data']['datasets'] = $novos;

        return $data;
    }

    /**
     * Ritmo de estudos / acertos × erros: barras verticais agrupadas, uma data real por grupo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aplicarBarrasRitmoEstudos(array $data): array
    {
        return $this->aplicarBarrasAgrupadasInstitucionais($data, 'chart_line_comparativo');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aplicarBarrasAgrupadasInstitucionais(array $data, string $canvasId): array
    {
        $data = $this->alinharSeriesAsDatas($data, $this->datasCompactasDoPeriodo());
        $labels = is_array($data['data']['labels'] ?? null) ? $data['data']['labels'] : [];
        $n = max(1, count($labels));
        $eHoras = $this->eGraficoRitmo($canvasId);

        $data['type'] = 'bar';
        $data['options'] = is_array($data['options'] ?? null) ? $data['options'] : [];
        $data['options']['plugins'] = is_array($data['options']['plugins'] ?? null)
            ? $data['options']['plugins']
            : [];
        $data['options']['layout'] = is_array($data['options']['layout'] ?? null)
            ? $data['options']['layout']
            : [];

        $cat = $n >= 16 ? 0.62 : ($n >= 13 ? 0.68 : 0.72);
        $bar = 0.88;
        $tickSize = $n >= 16 ? 9 : 10;
        $navy = RelatorioConsolidadoLayout::AZUL;

        $max = 0.0;
        if (isset($data['data']['datasets']) && is_array($data['data']['datasets'])) {
            foreach ($data['data']['datasets'] as $i => $ds) {
                if (! is_array($ds)) {
                    continue;
                }
                $cor = RelatorioConsolidadoLayout::corSerieBarra((string) ($ds['label'] ?? ''), $i);
                $data['data']['datasets'][$i]['type'] = 'bar';
                $data['data']['datasets'][$i]['fill'] = false;
                $data['data']['datasets'][$i]['backgroundColor'] = $cor;
                $data['data']['datasets'][$i]['borderColor'] = $cor;
                $data['data']['datasets'][$i]['borderWidth'] = 0;
                $data['data']['datasets'][$i]['categoryPercentage'] = $cat;
                $data['data']['datasets'][$i]['barPercentage'] = $bar;
                $data['data']['datasets'][$i]['borderRadius'] = 3;
                $data['data']['datasets'][$i]['borderSkipped'] = 'bottom';
                $data['data']['datasets'][$i]['shadowOffsetX'] = 0;
                $data['data']['datasets'][$i]['shadowOffsetY'] = 0;
                $data['data']['datasets'][$i]['shadowBlur'] = 0;
                unset(
                    $data['data']['datasets'][$i]['pointRadius'],
                    $data['data']['datasets'][$i]['lineTension'],
                    $data['data']['datasets'][$i]['spanGaps']
                );
                $data['data']['datasets'][$i]['datalabels'] = [
                    'align' => 'end',
                    'anchor' => 'end',
                    'offset' => $i === 1 ? 8 : 2,
                ];
                foreach (is_array($ds['data'] ?? null) ? $ds['data'] : [] as $valor) {
                    if (is_numeric($valor) && (float) $valor > $max) {
                        $max = (float) $valor;
                    }
                }
            }
        }

        $yMax = $max > 0 ? $max * 1.40 : 1;
        $yHoras = 10.0;
        if ($max > 10) {
            $yHoras = max(10.0, ceil($max / 2) * 2);
            if ($max >= $yHoras) {
                $yHoras += 2;
            }
        }
        $eixo = [
            'fontSize' => $tickSize,
            'fontColor' => $navy,
            'fontStyle' => '600',
        ];
        $yTicks = array_merge($eixo, [
            'beginAtZero' => true,
        ]);
        if ($eHoras) {
            $yTicks['max'] = $yHoras;
            $yTicks['stepSize'] = 2;
        } else {
            $yTicks['suggestedMax'] = $yMax;
        }
        $data['options']['scales'] = [
            'xAxes' => [[
                'stacked' => false,
                'categoryPercentage' => $cat,
                'barPercentage' => $bar,
                'gridLines' => [
                    'display' => false,
                    'drawBorder' => false,
                ],
                'ticks' => array_merge($eixo, [
                    'autoSkip' => false,
                    'maxTicksLimit' => $n,
                    'maxRotation' => 0,
                    'minRotation' => 0,
                ]),
            ]],
            'yAxes' => [[
                'stacked' => false,
                'gridLines' => [
                    'color' => 'rgba(0, 0, 0, 0.05)',
                    'drawBorder' => false,
                    'lineWidth' => 0.4,
                    'zeroLineColor' => 'rgba(0, 0, 0, 0.05)',
                    'drawTicks' => false,
                ],
                'scaleLabel' => [
                    'display' => true,
                    'labelString' => $eHoras ? 'Horas' : 'Questões',
                    'fontColor' => $navy,
                    'fontStyle' => '600',
                    'fontSize' => 10,
                ],
                'ticks' => $yTicks,
            ]],
        ];

        $data['options']['legend'] = [
            'display' => true,
            'position' => 'top',
            'labels' => [
                'boxWidth' => 10,
                'fontSize' => 10,
                'fontColor' => $navy,
                'fontStyle' => '600',
                'padding' => 16,
            ],
        ];
        $data['options']['cornerRadius'] = 3;
        $data['options']['plugins']['datalabels'] = [
            'display' => true,
            'anchor' => 'end',
            'align' => 'end',
            'offset' => 2,
            'clamp' => true,
            'clip' => false,
            'color' => $navy,
            'backgroundColor' => null,
            'borderWidth' => 0,
            'padding' => 0,
            'font' => ['size' => 7, 'weight' => '600'],
            'formatter' => $eHoras ? '__DATALABEL_HOURS_BAR__' : '__DATALABEL_COUNT_BAR__',
        ];
        $padding = is_array($data['options']['layout']['padding'] ?? null)
            ? $data['options']['layout']['padding']
            : [];
        $data['options']['layout']['padding'] = array_merge($padding, [
            'top' => max((int) ($padding['top'] ?? 0), 16),
            'bottom' => max((int) ($padding['bottom'] ?? 0), 8),
        ]);

        return $data;
    }

    /**
     * Paleta institucional, grade discreta e legendas secundárias.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aplicarIdentidadeVisualGrafico(array $data): array
    {
        $paleta = RelatorioConsolidadoLayout::paletaSeries();
        $type = (string) ($data['type'] ?? '');

        if ($type === 'line' && isset($data['data']['datasets']) && is_array($data['data']['datasets'])) {
            foreach ($data['data']['datasets'] as $i => $ds) {
                if (! is_array($ds)) {
                    continue;
                }
                $cor = $paleta[$i % count($paleta)];
                $data['data']['datasets'][$i]['borderColor'] = $cor;
                $data['data']['datasets'][$i]['backgroundColor'] = $cor;
                $data['data']['datasets'][$i]['fill'] = false;
                $data['data']['datasets'][$i]['pointRadius'] = 2;
                $data['data']['datasets'][$i]['borderWidth'] = 2;
            }
        }

        $data['options'] = is_array($data['options'] ?? null) ? $data['options'] : [];
        $data['options']['scales'] = is_array($data['options']['scales'] ?? null) ? $data['options']['scales'] : [];
        foreach (['xAxes', 'yAxes'] as $axis) {
            $existing = $data['options']['scales'][$axis] ?? [[]];
            if (! is_array($existing) || $existing === []) {
                $existing = [[]];
            }
            foreach ($existing as $j => $ax) {
                if (! is_array($ax)) {
                    $ax = [];
                }
                $grid = is_array($ax['gridLines'] ?? null) ? $ax['gridLines'] : [];
                $ax['gridLines'] = array_merge($grid, [
                    'color' => 'rgba(15, 23, 42, 0.06)',
                    'drawBorder' => false,
                    'lineWidth' => 0.5,
                ]);
                $ticks = is_array($ax['ticks'] ?? null) ? $ax['ticks'] : [];
                $tickExtras = [
                    'fontSize' => 9,
                    'fontColor' => RelatorioConsolidadoLayout::TEXTO_SEC,
                ];
                if ($axis === 'xAxes') {
                    $tickExtras['autoSkip'] = true;
                    $tickExtras['maxTicksLimit'] = 8;
                    $tickExtras['maxRotation'] = 45;
                    $tickExtras['minRotation'] = 0;
                }
                $ax['ticks'] = array_merge($ticks, $tickExtras);
                $existing[$j] = $ax;
            }
            $data['options']['scales'][$axis] = $existing;
        }

        $data['options']['legend'] = is_array($data['options']['legend'] ?? null) ? $data['options']['legend'] : [];
        $labels = is_array($data['options']['legend']['labels'] ?? null) ? $data['options']['legend']['labels'] : [];
        $data['options']['legend']['labels'] = array_merge($labels, [
            'boxWidth' => 10,
            'fontSize' => 10,
            'fontColor' => RelatorioConsolidadoLayout::TEXTO_SEC,
        ]);

        return $data;
    }

    /**
     * Limita backgroundColor ao nº de pontos (evita barra única com array enorme de cores).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizarCoresDataset(array $data): array
    {
        $datasets = $data['data']['datasets'] ?? null;
        if (! is_array($datasets)) {
            return $data;
        }

        foreach ($datasets as $i => $ds) {
            if (! is_array($ds)) {
                continue;
            }
            $points = is_array($ds['data'] ?? null) ? count($ds['data']) : 0;
            $bg = $ds['backgroundColor'] ?? null;
            if ($points > 0 && is_array($bg) && count($bg) > $points) {
                $data['data']['datasets'][$i]['backgroundColor'] = array_values(array_slice($bg, 0, max($points, 1)));
            }
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function extrairChartColors(string $html): array
    {
        $defaults = [
            '#00ACED',
            '#FF595E',
            '#FFCA3A',
            '#8AC926',
            '#6A4C93',
            '#FF70A6',
            '#6D4C3D',
            '#0D0221',
            '#5CF64A',
            '#F94144',
            '#F3722C',
            '#F9C74F',
            '#90BE6D',
            '#43AA8B',
            '#577590',
        ];
        if (! preg_match('/var\s+chartColors\s*=\s*\[(.*?)\]/s', $html, $m)) {
            return $defaults;
        }
        preg_match_all('/[\'"](#[0-9A-Fa-f]{3,8})[\'"]/', $m[1], $cm);
        $colors = array_values(array_unique($cm[1] ?? []));

        return $colors !== [] ? $colors : $defaults;
    }

    private function isHoursChart(string $canvasId): bool
    {
        return in_array($canvasId, [
            'chart_horas_diarias',
            'chart_line_comparativo',
            'chart_top_disciplinas',
            'chart_pizza_modalidades',
            'chart_horas_estudo',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aplicarDatalabelsOficiais(array $data, string $canvasId): array
    {
        $type = (string) ($data['type'] ?? '');
        $isHours = $this->isHoursChart($canvasId);

        // Garante legenda com nomes das disciplinas (bolha / evolução)
        if (in_array($canvasId, ['chart_bolha_questoes', 'chart_evolucao_materia'], true)) {
            $data['options']['legend']['display'] = true;
            $data['options']['legend']['labels'] = [
                'boxWidth' => 12,
                'fontSize' => 9,
            ];
        }

        // Labels aninhados do Chart.js v2 → texto único (QuickChart renderiza melhor)
        if (isset($data['data']['labels']) && is_array($data['data']['labels'])) {
            $data['data']['labels'] = array_map(static function ($label) {
                if (is_array($label)) {
                    return trim(implode(' ', array_map(static fn ($p) => (string) $p, $label)));
                }

                return $label;
            }, $data['data']['labels']);
            $data['data']['labels'] = PdfDatas::listaParaBrCompacta($data['data']['labels']);
        }

        // Barras: nomes das disciplinas no eixo X
        if ($type === 'bar' || $type === 'horizontalBar') {
            if ($type === 'bar') {
                $data['options']['scales']['xAxes'] = [[
                    'ticks' => [
                        'fontSize' => 8,
                        'autoSkip' => false,
                        'maxRotation' => 45,
                        'minRotation' => 45,
                    ],
                ]];
                // Eixo 0–120 é de percentual — não aplicar em gráficos de horas
                if (! $isHours && ! isset($data['options']['scales']['yAxes'])) {
                    $data['options']['scales']['yAxes'] = [[
                        'ticks' => ['beginAtZero' => true, 'max' => 120],
                    ]];
                }
            }
            if ($isHours) {
                // Horas estudadas: sem caixinhas de % (legenda permanece para séries)
                return $this->ocultarDatalabels($data);
            }
            // horizontalBar: preserva scales do HTML (já vêm prontas do Tutory)
            $data['options']['legend']['display'] = false;
            $data['options']['plugins']['datalabels'] = [
                'color' => '#333',
                'anchor' => 'end',
                'align' => 'end',
                'offset' => 6,
                'font' => ['size' => 10],
                'formatter' => '__DATALABEL_PERCENT__',
            ];

            return $data;
        }

        if ($type === 'pie' || $type === 'doughnut') {
            $data['options']['legend']['display'] = true;
            if ($isHours) {
                // Pizza de horas: sem rótulo de % sobre as fatias
                return $this->ocultarDatalabels($data);
            }
            $data['options']['plugins']['datalabels'] = [
                'color' => '#fff',
                'backgroundColor' => '#000',
                'anchor' => 'end',
                'align' => 'end',
                'offset' => -16,
                'font' => ['size' => 10],
                'formatter' => '__DATALABEL_QUESTOES__',
            ];

            return $data;
        }

        if ($type === 'bubble') {
            $data['options']['plugins']['datalabels'] = [
                'anchor' => 'end',
                'align' => 'end',
                'offset' => 8,
                'color' => '#fff',
                'backgroundColor' => '#000',
                'borderRadius' => 0,
                'padding' => 4,
                'font' => ['size' => 10],
                'formatter' => '__DATALABEL_BUBBLE_PERCENT__',
            ];
            $padding = is_array($data['options']['layout']['padding'] ?? null)
                ? $data['options']['layout']['padding']
                : [];
            $data['options']['layout']['padding'] = array_merge($padding, [
                'top' => 20,
                'right' => 16,
            ]);

            return $data;
        }

        if ($type === 'line') {
            $data['options'] = is_array($data['options'] ?? null) ? $data['options'] : [];
            $data['options']['plugins'] = is_array($data['options']['plugins'] ?? null)
                ? $data['options']['plugins']
                : [];
            $data['options']['layout'] = is_array($data['options']['layout'] ?? null)
                ? $data['options']['layout']
                : [];
            $data['options']['plugins']['datalabels'] = [
                'anchor' => 'center',
                'align' => 'top',
                'offset' => 8,
                'clamp' => true,
                'clip' => false,
                'color' => RelatorioConsolidadoLayout::AZUL,
                'backgroundColor' => 'rgba(255,255,255,0.82)',
                'borderWidth' => 0,
                'padding' => 2,
                'font' => ['size' => 8, 'weight' => 'bold'],
                'formatter' => $isHours ? '__DATALABEL_HOURS__' : '__DATALABEL_VALUE__',
            ];
            if (isset($data['data']['datasets']) && is_array($data['data']['datasets'])) {
                foreach ($data['data']['datasets'] as $i => $ds) {
                    if (! is_array($ds)) {
                        continue;
                    }
                    $data['data']['datasets'][$i]['datalabels'] = [
                        'align' => $i % 2 === 0 ? 'top' : 'bottom',
                        'anchor' => 'center',
                        'offset' => 8,
                    ];
                }
            }
            $padding = is_array($data['options']['layout']['padding'] ?? null)
                ? $data['options']['layout']['padding']
                : [];
            $data['options']['layout']['padding'] = array_merge($padding, [
                'top' => max((int) ($padding['top'] ?? 0), 28),
                'bottom' => max((int) ($padding['bottom'] ?? 0), 24),
            ]);

            return $data;
        }

        unset($data['options']['plugins']['datalabels']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ocultarDatalabels(array $data): array
    {
        $data['options'] = is_array($data['options'] ?? null) ? $data['options'] : [];
        $data['options']['plugins'] = is_array($data['options']['plugins'] ?? null)
            ? $data['options']['plugins']
            : [];
        $data['options']['plugins']['datalabels'] = ['display' => false];

        return $data;
    }

    private function extrairObjetoJsBalanceado(string $source, int $start): ?string
    {
        if (! isset($source[$start]) || $source[$start] !== '{') {
            return null;
        }
        $depth = 0;
        $inStr = null;
        $escape = false;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            $ch = $source[$i];
            if ($inStr !== null) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === $inStr) {
                    $inStr = null;
                }

                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = $ch;

                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chartConfig
     */
    private function quickChartPngDataUri(array $chartConfig): ?string
    {
        try {
            // chart como string JS para permitir formatter() (caixinhas pretas "N questões" / "N%")
            $chartJs = json_encode($chartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($chartJs === false) {
                return null;
            }
            $replacements = [
                '"__DATALABEL_QUESTOES__"' => 'function(value){return Number(value).toFixed(0)+" questões";}',
                '"__DATALABEL_PERCENT__"' => 'function(value){return Number(value).toFixed(0)+"%";}',
                '"__DATALABEL_HOURS__"' => 'function(value){var n=Number(value&&typeof value==="object"&&value.y!=null?value.y:value);if(!isFinite(n))return "";var r=Math.round(n*10)/10;if(r===0)return "";return (Math.abs(r%1)<1e-9?String(Math.round(r)):String(r).replace(".",","))+"h";}',
                '"__DATALABEL_HOURS_BAR__"' => 'function(value){if(value==null||value==="")return "";var n=Number(value&&typeof value==="object"&&value.y!=null?value.y:value);if(!isFinite(n))return "";var r=Math.round(n*10)/10;return (Math.abs(r%1)<1e-9?String(Math.round(r)):String(r).replace(".",","))+"h";}',
                '"__DATALABEL_COUNT_BAR__"' => 'function(value){if(value==null||value==="")return "";var n=Number(value&&typeof value==="object"&&value.y!=null?value.y:value);if(!isFinite(n))return "";return String(Math.round(n));}',
                '"__DATALABEL_VALUE__"' => 'function(value){var n=Number(value&&typeof value==="object"&&value.y!=null?value.y:value);if(!isFinite(n))return "";var r=Math.round(n*10)/10;if(r===0)return "";return Math.abs(r%1)<1e-9?String(Math.round(r)):String(r).replace(".",",");}',
                '"__DATALABEL_BUBBLE_PERCENT__"' => 'function(value){return value&&value.r!=null?Number(value.r*10).toFixed(0)+"%":"";}',
                // legado
                '"__DATALABEL_FORMATTER__"' => 'function(value){return Number(value).toFixed(0)+" questões";}',
            ];
            $chartJs = str_replace(array_keys($replacements), array_values($replacements), $chartJs);

            $type = (string) ($chartConfig['type'] ?? '');
            $labelCount = is_array($chartConfig['data']['labels'] ?? null)
                ? count($chartConfig['data']['labels'])
                : 1;
            $width = 900;
            $height = 420;
            if ($type === 'horizontalBar') {
                $height = max(110, min(420, 48 + ($labelCount * 38)));
            } elseif ($type === 'pie' || $type === 'doughnut') {
                // Pizza: render nítido na proporção do PDF (único ajuste de tamanho)
                $width = self::PIE_RENDER_WIDTH;
                $height = self::PIE_RENDER_HEIGHT;
            } elseif ($type === 'bar') {
                $height = $labelCount <= 4 ? 320 : 280;
            }

            $payload = [
                'width' => $width,
                'height' => $height,
                'devicePixelRatio' => 2,
                'format' => 'png',
                'backgroundColor' => 'white',
                'version' => '2.9.4',
                'plugins' => ['datalabels'],
                'chart' => $chartJs,
            ];

            $resp = Http::timeout(45)
                ->asJson()
                ->post('https://quickchart.io/chart', $payload);

            if ($resp->successful() && strlen($resp->body()) > 100) {
                $body = $resp->body();
                $ctype = (string) ($resp->header('Content-Type') ?? '');
                if (str_starts_with($body, "\x89PNG") || str_contains($ctype, 'image')) {
                    return 'data:image/png;base64,'.base64_encode($body);
                }
                $this->log('QuickChart resposta inesperada: '.substr($body, 0, 180));
            }

            // GET fallback (sem function — só útil se não houver formatter)
            if (! str_contains($chartJs, 'function(value)')) {
                $url = 'https://quickchart.io/chart?c='.rawurlencode($chartJs)
                    .'&w='.$width.'&h='.$height.'&bkg=white&f=png&v=2.9.4';
                $get = Http::timeout(45)->get($url);
                if ($get->successful() && strlen($get->body()) > 100) {
                    return 'data:image/png;base64,'.base64_encode($get->body());
                }
            }
        } catch (Throwable $exc) {
            $this->log('QuickChart erro: '.$exc->getMessage());
        }

        return null;
    }

    private function salvarBytesPdf(string $nomeAluno, string $bytes, string $model = 'questoes', ?string $id = null): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $destino = $this->caminhoDestinoPdf($nomeAluno, $model, $id);
        file_put_contents($destino, $bytes);
        $this->log("[{$nomeAluno}] Arquivo salvo: {$destino}");

        return $destino;
    }

    private function formatarDuracao(float $segundos): string
    {
        $total = (int) round($segundos);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;
        if ($h > 0) {
            return "{$h}h {$m}min {$s}s";
        }
        if ($m > 0) {
            return "{$m}min {$s}s";
        }

        return "{$s}s";
    }

    /**
     * @param  list<string>  $pdfs
     * @param  list<string>  $falhas
     * @param  list<array{nome: string, sucesso: bool, tentativas: int}>  $resultados
     */
    private function gravarLogResumo(
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fim,
        int $total,
        array $pdfs,
        array $falhas,
        array $resultados,
    ): string {
        $caminho = $this->pastaDownload.'/log_download_'.$inicio->format('Ymd_His').'.txt';
        $linhas = [
            'Relatórios Tutory - CLI/HTTP',
            'Início: '.$inicio->format('d/m/Y H:i:s'),
            'Fim:    '.$fim->format('d/m/Y H:i:s'),
            'Duração: '.$this->formatarDuracao($fim->getTimestamp() - $inicio->getTimestamp()),
            'Alunos: '.$total,
            'Modelos-fonte: '.implode(', ', array_column($this->relatorios(), 'model')).' → PDF consolidado',
            'PDFs: '.count($pdfs),
            'Falhas: '.count($falhas),
            "Pasta: {$this->pastaDownload}",
            '',
            'Arquivos:',
        ];
        $linhas = array_merge($linhas, $pdfs !== [] ? array_map(static fn ($p) => '- '.basename($p), $pdfs) : ['- (nenhum)']);
        $linhas[] = '';
        $linhas[] = 'Status:';
        foreach ($resultados as $r) {
            $linhas[] = '- ['.($r['sucesso'] ? 'OK' : 'FALHA')."] {$r['nome']} (tentativas: {$r['tentativas']})";
        }
        if ($falhas !== []) {
            $linhas[] = '';
            $linhas[] = 'Falhas finais:';
            foreach ($falhas as $f) {
                $linhas[] = "- {$f}";
            }
        }
        file_put_contents($caminho, implode("\n", $linhas)."\n");

        return $caminho;
    }

    /**
     * Aluno com menos de 15 dias no cadastro local não gera PDF.
     *
     * @param  list<array{nome: string, id: string}>  $alunos
     * @return list<array{nome: string, id: string}>
     */
    private function semAlunosNovatos(array $alunos): array
    {
        try {
            $ficam = [];
            foreach ($alunos as $aluno) {
                $local = Aluno::encontrarPorTutoryId((string) ($aluno['id'] ?? ''))
                    ?? Aluno::encontrarPorNome((string) ($aluno['nome'] ?? ''));
                if ($local !== null && $local->ehNovato()) {
                    $this->log("[{$local->nome}] Aluno novato: PDF não gerado (cadastro com menos de ".Aluno::DIAS_NOVATO.' dias).');
                    $this->registrarNovato($local->nome);

                    continue;
                }
                $ficam[] = $aluno;
            }

            return $ficam;
        } catch (QueryException|SQLiteDatabaseDoesNotExistException $exc) {
            $this->log('Banco local indisponível ao identificar alunos novatos; a geração segue para todos.');
            $this->log($exc->getMessage());

            return $alunos;
        }
    }

    private function baixarTodos(): void
    {
        $inicio = $this->resumoExecucao->inicio ?? new \DateTimeImmutable('now');
        $relatorios = $this->relatorios();
        $this->log('Processo iniciado em: '.$inicio->format('d/m/Y H:i:s'));
        $motivo = $this->motivoSemPuppeteer();
        $motor = $motivo === null
            ? 'Puppeteer (Node)'
            : 'PHP/Dompdf ('.$motivo.')';
        $this->log('Modo: CLI/HTTP → cadastrar-relatorio-coach + 1 PDF consolidado via '.$motor);
        $this->log('Fontes: '.implode(', ', array_map(
            static fn (array $r): string => $r['nome'].' ('.$r['model'].')',
            $relatorios
        )));

        $alunos = $this->semAlunosNovatos($this->coletarAlunosAtivos());
        if ($this->teste) {
            $alvo = mb_strtolower(self::ALUNO_TESTE);
            $alunos = array_values(array_filter(
                $alunos,
                static fn (array $a) => str_contains(mb_strtolower($a['nome']), $alvo)
            ));
            if ($alunos !== []) {
                $this->log('Modo --teste: '.$alunos[0]['nome'].' (id '.$alunos[0]['id'].')');
            } else {
                $this->log("Modo --teste: '".self::ALUNO_TESTE."' não encontrado.");
            }
        }

        if ($alunos === []) {
            $fim = new \DateTimeImmutable('now');
            if ($this->resumoExecucao !== null) {
                $this->resumoExecucao->fim = $fim;
            }
            $log = $this->gravarLogResumo($inicio, $fim, 0, [], [], []);
            $this->registrarGeracao([], $log);
            $this->log("Nenhum aluno. Log: {$log}");

            return;
        }

        /** @var array<string, array{nome: string, sucesso: bool, arquivo: ?string, tentativas: int, meta: array{nome: string, id: string}}> $resultados */
        $resultados = [];
        foreach ($alunos as $a) {
            $resultados[$a['id']] = [
                'nome' => $a['nome'],
                'sucesso' => false,
                'arquivo' => null,
                'tentativas' => 0,
                'meta' => $a,
            ];
        }
        $chaves = array_keys($resultados);

        $processarLote = function (array $lista, int $rodada) use (&$resultados, $relatorios): void {
            $total = count($lista);
            foreach ($lista as $i => $chave) {
                $resultados[$chave]['tentativas']++;
                $t = $resultados[$chave]['tentativas'];
                $aluno = $resultados[$chave]['meta'];
                $rotulo = $aluno['nome'];
                $this->log(str_repeat('=', 50));
                $this->log("[rodada {$rodada}] Aluno ".($i + 1)."/{$total}: {$rotulo} (tentativa {$t}/".self::MAX_TENTATIVAS.')');
                try {
                    $arquivo = $this->processarAlunoConsolidado($aluno, $relatorios);
                } catch (Throwable $exc) {
                    $this->log("[{$rotulo}] ERRO: ".$exc->getMessage());
                    $arquivo = null;
                }
                if ($arquivo !== null) {
                    $resultados[$chave]['sucesso'] = true;
                    $resultados[$chave]['arquivo'] = $arquivo;
                    $this->log("[{$rotulo}] SUCESSO (PDF consolidado)");
                } else {
                    $resultados[$chave]['sucesso'] = false;
                    $resultados[$chave]['arquivo'] = null;
                    $this->log("[{$rotulo}] FALHA");
                }
            }
        };

        $processarLote($chaves, 1);
        for ($rodada = 2; $rodada <= self::MAX_TENTATIVAS; $rodada++) {
            $pendentes = array_values(array_filter($chaves, static fn ($k) => ! $resultados[$k]['sucesso']));
            if ($pendentes === []) {
                $this->log(str_repeat('=', 50));
                $this->log('Nenhuma falha restante.');
                break;
            }
            $this->log(str_repeat('=', 50));
            $this->log('Reprocessando '.count($pendentes)." falha(s) (rodada {$rodada}/".self::MAX_TENTATIVAS.')...');
            $processarLote($pendentes, $rodada);
        }

        $pdfs = [];
        $falhas = [];
        foreach ($resultados as $r) {
            if ($r['sucesso'] && $r['arquivo']) {
                $pdfs[] = $r['arquivo'];
            }
            if (! $r['sucesso']) {
                $falhas[] = $r['nome'];
            }
        }
        $lista = array_map(static fn ($k) => $resultados[$k], $chaves);
        $fim = new \DateTimeImmutable('now');
        if ($this->resumoExecucao !== null) {
            $this->resumoExecucao->fim = $fim;
        }
        $log = $this->gravarLogResumo($inicio, $fim, count($alunos), $pdfs, $falhas, $lista);
        $this->registrarGeracao($lista, $log);

        $this->log(str_repeat('=', 50));
        $this->log('Início: '.$inicio->format('d/m/Y H:i:s'));
        $this->log('Fim:    '.$fim->format('d/m/Y H:i:s'));
        $this->log('Duração: '.$this->formatarDuracao($fim->getTimestamp() - $inicio->getTimestamp()));
        $this->log('PDFs consolidados: '.count($pdfs));
        $this->log('Falhas finais: '.count($falhas));
        foreach ($chaves as $chave) {
            if ($resultados[$chave]['sucesso']) {
                continue;
            }
            $this->log("- {$resultados[$chave]['nome']} (tentativas: {$resultados[$chave]['tentativas']})");
        }
        $this->log("Arquivos em: {$this->pastaDownload}");
        $this->log("Log salvo em: {$log}");
    }

    /**
     * @param  list<array{nome: string, sucesso: bool}>  $lista
     */
    private function registrarGeracao(array $lista, string $log): void
    {
        if ($this->resumoExecucao === null) {
            return;
        }

        $this->resumoExecucao->geracoes = array_map(
            static fn (array $item): array => [
                'nome' => $item['nome'],
                'sucesso' => (bool) $item['sucesso'],
            ],
            $lista
        );
        $this->resumoExecucao->logArquivo = basename($log);
    }

    /**
     * Abre os 5 relatórios oficiais e compõe um único PDF com as seções pedidas.
     *
     * @param  array{nome: string, id: string}  $aluno
     * @param  list<array{model: string, nome: string, slug: string}>  $relatorios
     */
    private function processarAlunoConsolidado(array $aluno, array $relatorios): ?string
    {
        $nome = $aluno['nome'];
        /** @var array<string, string> $urls */
        $urls = [];
        /** @var array<string, string> $htmls */
        $htmls = [];

        foreach ($relatorios as $relatorio) {
            $pagina = $this->abrirPaginaRelatorio($aluno, $relatorio);
            if ($pagina === null) {
                $aviso = "[{$nome}] Falha ao abrir {$relatorio['nome']} — consolidado abortado";
                $this->ultimoErroPdf = $this->ultimoErroPdf ?: $aviso;
                $this->log($aviso);

                return null;
            }
            $urls[$pagina['model']] = $pagina['url'];
            $htmls[$pagina['model']] = $pagina['html'];
        }

        $destino = $this->caminhoDestinoPdf($nome, 'consolidado', $aluno['id']);
        $ok = false;
        $motivo = $this->motivoSemPuppeteer();
        if ($motivo === null) {
            $ok = $this->gerarPdfConsolidadoComPuppeteer($nome, $urls, $destino);
            if (! $ok) {
                $this->log("[{$nome}] Compositor Puppeteer falhou — usando fallback PHP/Dompdf");
            }
        } else {
            $this->log("[{$nome}] {$motivo} — gerando o consolidado com PHP/Dompdf");
        }
        if (! $ok) {
            @unlink($destino);
            $ok = $this->gerarPdfConsolidadoDoHtml($nome, $htmls, $destino);
        }
        if (! $ok) {
            @unlink($destino);
            $this->ultimoErroPdf = "[{$nome}] Falha ao montar o PDF consolidado com Dompdf.";

            return null;
        }

        $this->salvarMetricasConsolidado($destino, $htmls);

        return $destino;
    }
}
