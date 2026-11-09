<?php

namespace App\Services\Tutory;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Cookie\FileCookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sessão HTTP no admin da Tutory para listar alunos.
 *
 * Nos ativos, cada card abre Ações → Cadastro (`/alunos/index?aid={id}`).
 * O e-mail e o telefone vêm desse formulário, não do texto do card da lista:
 * o card pode ainda mostrar um login antigo. Telefone no padrão 55 + DDD + número, só dígitos.
 */
class TutoryAlunosClient
{
    private const BASE = 'https://admin.tutory.com.br';

    private string $urlLogin;

    private string $usuario;

    private string $senha;

    private int $timeout;

    private string $cookieFile;

    private ?FileCookieJar $cookieJar = null;

    private ?string $bearerToken = null;

    /** @var callable(string): void */
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $message): void {
            echo $message.PHP_EOL;
        };

        $loginUrl = trim((string) env('LOGIN_URL', ''));
        $this->urlLogin = $loginUrl !== '' ? $loginUrl : self::BASE.'/login';
        $usuario = trim((string) env('LOGIN_USER', ''));
        $senha = trim((string) env('LOGIN_PASSWORD', ''));
        $this->usuario = $usuario !== '' ? $usuario : 'missaonomeacao';
        $this->senha = $senha !== '' ? $senha : '05473793150';
        $this->timeout = (int) (env('TIMEOUT') ?: 120);
        $this->cookieFile = sys_get_temp_dir().'/tutory_sync_cookies_'.getmypid().'.json';
        $this->cookieJar = new FileCookieJar($this->cookieFile, true);
    }

    public function login(): void
    {
        $this->log('Abrindo página de login da Tutory...');
        $loginPage = $this->client()->get($this->urlLogin);
        if ($loginPage->status() >= 400) {
            throw new RuntimeException('Não foi possível abrir a página de login (HTTP '.$loginPage->status().').');
        }

        $this->log('Enviando credenciais para /intent/login...');
        $response = $this->client()
            ->asForm()
            ->withHeaders(['Referer' => $this->urlLogin])
            ->post('/intent/login', [
                'account' => $this->usuario,
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
            $consulta = $this->client()
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->get('/alunos/consulta', ['status' => 'ativos']);
            $this->bearerToken = $this->extrairToken($consulta->body());
        }
        $this->log($this->bearerToken !== null
            ? 'Login realizado (Bearer token obtido)'
            : 'Login realizado (sessão por cookie)');
    }

    /**
     * @return list<array{id: string, nome: string, email: string, telefone?: string|null}>
     */
    public function coletarAlunosAtivos(): array
    {
        return $this->coletarPorStatus('ativos', true);
    }

    /**
     * Inativos na Tutory. O filtro da consulta é status=desativados.
     *
     * @return list<array{id: string, nome: string, email: string}>
     */
    public function coletarAlunosDesativados(): array
    {
        return $this->coletarPorStatus('desativados', false);
    }

    /**
     * @return list<array{id: string, nome: string, email: string}>
     */
    private function coletarPorStatus(string $status, bool $obrigatorio): array
    {
        $this->log('Pesquisando alunos '.$status.' em /alunos/consulta...');
        $resp = $this->client()->get('/alunos/consulta', ['status' => $status]);
        $html = $resp->body();
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            $resp = $this->client()->asForm()->post('/alunos/consulta', ['status' => $status]);
            $html = $resp->body();
        }
        if (! str_contains($html, 'pesquisa-aluno-container')) {
            if ($obrigatorio) {
                throw new RuntimeException('Lista de alunos '.$status.' não encontrada em /alunos/consulta.');
            }
            $this->log('Nenhum aluno '.$status.' em /alunos/consulta.');

            return [];
        }

        $alunos = [];
        $vistos = [];
        $pagina = 1;
        $urlAtual = self::BASE.'/alunos/consulta?status='.$status;

        while (true) {
            $paginaAlunos = $this->parseAlunosDaPagina($html);
            $this->log("Coletando página {$pagina}: ".count($paginaAlunos).' aluno(s)');
            foreach ($paginaAlunos as $aluno) {
                if ($aluno['id'] === '' || isset($vistos[$aluno['id']])) {
                    continue;
                }
                $vistos[$aluno['id']] = true;
                if ($status === 'ativos') {
                    $aluno = $this->anexarDadosDoCadastro($aluno, $urlAtual);
                } else {
                    unset($aluno['cadastro_href']);
                    if ($aluno['email'] === '') {
                        $aluno['email'] = $this->buscarEmailNoCadastro($aluno['id']);
                    }
                }
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

        $this->log('Total de alunos '.$status.': '.count($alunos));

        return $alunos;
    }

    /**
     * DDD 61 e telefone 9912-38860 viram 5561991238860.
     */
    public static function montarTelefone(string $ddd, string $numero): ?string
    {
        $dddDigitos = preg_replace('/\D+/', '', $ddd) ?? '';
        $numeroDigitos = preg_replace('/\D+/', '', $numero) ?? '';
        if ($dddDigitos === '' || $numeroDigitos === '') {
            return null;
        }

        $telefone = '55'.$dddDigitos.$numeroDigitos;
        if (strlen($telefone) > 50) {
            return null;
        }

        return $telefone;
    }

    /**
     * @return array{encontrou: bool, telefone: string|null}
     */
    public function extrairTelefoneDoCadastro(string $html): array
    {
        $xp = $this->loadDom($html);
        $ddd = $xp->query('//*[@id="cadastroAlunoDDD" or @name="ddd"]');
        $celular = $xp->query('//*[@id="cadastroAlunoTel" or @name="celular"]');
        $temDdd = $ddd !== false && $ddd->length > 0 && $ddd->item(0) instanceof DOMElement;
        $temCelular = $celular !== false && $celular->length > 0 && $celular->item(0) instanceof DOMElement;
        if (! $temDdd && ! $temCelular) {
            return ['encontrou' => false, 'telefone' => null];
        }

        $valorDdd = $temDdd ? $this->valorSelecionado($ddd->item(0)) : '';
        $valorCelular = $temCelular ? trim($celular->item(0)->getAttribute('value')) : '';

        return [
            'encontrou' => true,
            'telefone' => self::montarTelefone($valorDdd, $valorCelular),
        ];
    }

    /**
     * @param  array{id: string, nome: string, email: string, cadastro_href?: string}  $aluno
     * @return array{id: string, nome: string, email: string, telefone?: string|null}
     */
    private function anexarDadosDoCadastro(array $aluno, string $urlAtual): array
    {
        $href = trim((string) ($aluno['cadastro_href'] ?? ''));
        unset($aluno['cadastro_href']);
        $url = $this->urlDoCadastro($href, $urlAtual, $aluno['id']);
        $this->log('Abrindo cadastro do aluno '.$aluno['id'].' (Ações → Cadastro)...');
        $resp = $this->client()->get($url);
        if ($resp->status() >= 400) {
            $this->log('Cadastro indisponível para o aluno '.$aluno['id'].' (HTTP '.$resp->status().'). Dados do card mantidos.');

            return $aluno;
        }

        $html = $resp->body();
        $emailCadastro = $this->extrairEmailDoCadastro($html);
        if ($emailCadastro !== '') {
            if ($aluno['email'] !== '' && $aluno['email'] !== $emailCadastro) {
                $this->log('E-mail do card "'.$aluno['email'].'" substituído pelo cadastro "'.$emailCadastro.'" (Tutory id '.$aluno['id'].').');
            } elseif ($aluno['email'] === '') {
                $this->log('E-mail obtido no cadastro Tutory id='.$aluno['id']);
            }
            $aluno['email'] = $emailCadastro;
        }

        $lido = $this->extrairTelefoneDoCadastro($html);
        if (! $lido['encontrou']) {
            $this->log('Cadastro do aluno '.$aluno['id'].' sem DDD e telefone. Telefone local mantido.');

            return $aluno;
        }

        $aluno['telefone'] = $lido['telefone'];
        $this->log($lido['telefone'] !== null
            ? 'Telefone da Tutory id='.$aluno['id'].': '.$lido['telefone']
            : 'Cadastro da Tutory id='.$aluno['id'].' sem telefone.');

        return $aluno;
    }

    /**
     * Campo do formulário Ações → Cadastro. Não usa o primeiro e-mail do HTML:
     * a página pode ainda citar um login antigo no card ou em script.
     */
    public function extrairEmailDoCadastro(string $html): string
    {
        $xp = $this->loadDom($html);
        $queries = [
            '//*[@id="cadastroAlunoEmail" or @id="cadastroAlunoMail"]',
            '//input[@name="email" or @type="email"]',
            '//input[contains(translate(@name,"EMAIL","email"),"email")]',
            '//textarea[@name="email" or contains(translate(@name,"EMAIL","email"),"email")]',
        ];
        foreach ($queries as $query) {
            $nodes = $xp->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $valor = $this->valorDeCampoEmail($node);
                if ($valor !== '') {
                    return $valor;
                }
            }
        }

        return '';
    }

    private function valorDeCampoEmail(DOMElement $node): string
    {
        $valor = trim($node->getAttribute('value'));
        if ($valor === '') {
            $valor = trim((string) $node->textContent);
        }
        if (! filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return mb_strtolower($valor);
    }

    private function extrairHrefCadastro(DOMXPath $xp, DOMElement $card): string
    {
        $links = $xp->query('.//a[contains(concat(" ", normalize-space(@class), " "), " dropdown-item ")]', $card);
        if ($links === false) {
            return '';
        }
        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $texto = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $link->textContent) ?? ''));
            if ($texto !== 'cadastro') {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if ($this->hrefInutil($href)) {
                continue;
            }

            return $href;
        }

        return '';
    }

    private function urlDoCadastro(string $href, string $urlAtual, string $id): string
    {
        if ($href !== '') {
            return $this->resolverRelativo($href, $urlAtual);
        }

        return self::BASE.'/alunos/index?aid='.rawurlencode($id);
    }

    private function resolverRelativo(string $href, string $urlAtual): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        $path = parse_url($urlAtual, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        return self::BASE.$dir.ltrim($href, '/');
    }

    private function valorSelecionado(DOMElement $select): string
    {
        if ($select->tagName !== 'select') {
            return trim($select->getAttribute('value'));
        }

        foreach ($select->getElementsByTagName('option') as $opcao) {
            if (! $opcao instanceof DOMElement || ! $opcao->hasAttribute('selected')) {
                continue;
            }
            $valor = trim($opcao->getAttribute('value'));

            return $valor !== '' ? $valor : trim($opcao->textContent);
        }

        return '';
    }

    public function encerrar(): void
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
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

    private function extrairToken(string $html): ?string
    {
        if (preg_match('/adminUser\s*=\s*\{(.*?)\}\s*;/s', $html, $m)) {
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
     * @return list<array{id: string, nome: string, email: string, cadastro_href?: string}>
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

            $id = $this->extrairIdDoCard($xp, $card);
            if ($id === '') {
                $this->log("AVISO: sem data-id para {$nome} — ignorado");

                continue;
            }

            $aluno = [
                'id' => $id,
                'nome' => $nome,
                'email' => $this->extrairEmailDoCard($xp, $card),
            ];
            $cadastro = $this->extrairHrefCadastro($xp, $card);
            if ($cadastro !== '') {
                $aluno['cadastro_href'] = $cadastro;
            }
            $alunos[] = $aluno;
        }

        return $alunos;
    }

    private function extrairIdDoCard(DOMXPath $xp, DOMElement $card): string
    {
        $attr = trim($card->getAttribute('data-id'));
        if (preg_match('/^\d+$/', $attr)) {
            return $attr;
        }
        $links = $xp->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' btn-generate-report ')]", $card);
        if ($links !== false && $links->length > 0 && $links->item(0) instanceof DOMElement) {
            $id = trim($links->item(0)->getAttribute('data-id'));
            if ($id !== '') {
                return $id;
            }
        }
        $vals = $xp->query('.//*[@value]', $card);
        if ($vals !== false) {
            foreach ($vals as $el) {
                if (! $el instanceof DOMElement) {
                    continue;
                }
                $v = trim($el->getAttribute('value'));
                if (preg_match('/^\d{4,}$/', $v)) {
                    return $v;
                }
            }
        }

        return '';
    }

    private function extrairEmailDoCard(DOMXPath $xp, DOMElement $card): string
    {
        $attr = trim($card->getAttribute('data-email'));
        if (filter_var($attr, FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($attr);
        }
        $mailtos = $xp->query('.//a[starts-with(@href, "mailto:")]', $card);
        if ($mailtos !== false) {
            foreach ($mailtos as $a) {
                if (! $a instanceof DOMElement) {
                    continue;
                }
                $email = preg_replace('#^mailto:#i', '', (string) $a->getAttribute('href')) ?? '';
                $email = trim(explode('?', $email)[0]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return mb_strtolower($email);
                }
            }
        }

        $htmlCard = '';
        $doc = $card->ownerDocument;
        if ($doc instanceof \DOMDocument) {
            $htmlCard = (string) $doc->saveHTML($card);
        }

        return $this->primeiroEmailNoTexto($htmlCard !== '' ? $htmlCard : (string) $card->textContent);
    }

    private function buscarEmailNoCadastro(string $id): string
    {
        foreach (['/alunos/editar/'.$id, '/alunos/cadastro/'.$id, '/alunos/'.$id] as $path) {
            $resp = $this->client()->get($path);
            if ($resp->status() >= 400) {
                continue;
            }
            $email = $this->extrairEmailDoCadastro($resp->body());
            if ($email !== '') {
                $this->log("E-mail obtido no cadastro Tutory id={$id}");

                return $email;
            }
        }

        return '';
    }

    private function primeiroEmailNoTexto(string $texto): string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $texto, $m)) {
            $email = mb_strtolower($m[0]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
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

    private function loadDom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
