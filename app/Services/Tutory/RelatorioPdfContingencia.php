<?php

namespace App\Services\Tutory;

use App\Http\Util\MailHelper;
use App\Models\Aluno;
use App\Services\Desempenho\AvaliadorDesempenho;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Gera o PDF consolidado de um aluno para um período já liberado.
 * O admin pode só baixar ou também reenviar o e-mail do relatório.
 */
class RelatorioPdfContingencia
{
    public const EMAIL_NAO_SOLICITADO = 'nao_solicitado';

    public const EMAIL_ENVIADO = 'enviado';

    public const EMAIL_FALHOU = 'falhou';

    public const HEADER_EMAIL = 'X-Contingencia-Email';

    public const HEADER_EMAIL_MENSAGEM = 'X-Contingencia-Email-Mensagem';

    /**
     * @param  array{year_month: string, period: string, label?: string, inicio?: string, fim?: string}  $periodo
     */
    public function gerar(Aluno $aluno, array $periodo, ?callable $logger = null): string
    {
        $tutoryId = trim((string) $aluno->tutory_id);
        if ($tutoryId === '') {
            throw new RuntimeException('Aluno sem id da Tutory. Sincronize os alunos ativos e tente de novo.');
        }

        $mes = \DateTimeImmutable::createFromFormat('Y-m-d', $periodo['year_month'].'-01');
        if ($mes === false) {
            throw new RuntimeException('Mês do período inválido.');
        }

        $log = $this->loggerComArquivo($logger);

        $downloader = new CoachReportDownloader(
            periodo: (string) $periodo['period'],
            logger: $log,
            teste: false,
            referenciaMes: $mes,
        );

        try {
            return $downloader->gerarPdfParaAluno($tutoryId, $aluno->nome, $aluno);
        } catch (Throwable $exc) {
            $this->registrarErro('Falha ao gerar o PDF da contingência.', $aluno, $periodo, $exc);
            throw $exc;
        }
    }

    /**
     * Impede o reenvio quando o cadastro não pode receber o relatório.
     */
    public function validarEnvio(Aluno $aluno): void
    {
        if ($aluno->isTeacher()) {
            throw new RuntimeException('A mentora não recebe o e-mail do relatório pelo cadastro de aluno.');
        }

        $email = trim((string) $aluno->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail do aluno inválido: '.($email !== '' ? $email : '(vazio)'));
        }
    }

    /**
     * Envia o PDF já gerado. Não grava faixas no aluno (a contingência
     * pode ser de um período antigo e não deve sobrescrever o dashboard).
     *
     * @param  array{year_month: string, period: string, label?: string, inicio?: string, fim?: string}  $periodo
     */
    public function enviar(Aluno $aluno, array $periodo, string $caminhoPdf, ?callable $logger = null): void
    {
        $this->validarEnvio($aluno);

        if (! is_file($caminhoPdf)) {
            throw new RuntimeException('Arquivo do PDF não encontrado para envio.');
        }

        $log = $this->loggerComArquivo($logger);
        $periodoLabel = $this->rotuloPeriodo($periodo);
        $chavePeriodo = $periodo['year_month'].'-'.$periodo['period'];

        if (! $aluno->recebe_email) {
            $aviso = "[{$aluno->nome}] recebe_email está desmarcado; o reenvio da contingência segue mesmo assim.";
            $log($aviso);
            Log::channel('pdf-contingencia')->warning($aviso, $this->contexto($aluno, $periodo));
            Log::warning($aviso, $this->contexto($aluno, $periodo));
        }

        $avaliacao = $this->avaliarPdf($aluno, $caminhoPdf, $chavePeriodo);
        $blocos = $avaliacao['blocos'] ?? [];
        $resumo = $avaliacao['resumo'] ?? null;

        $log("[{$aluno->nome}] Enviando o e-mail do relatório para {$aluno->email}…");

        try {
            MailHelper::emailRelatorioCoach(
                [
                    'nome' => $aluno->nome,
                    'periodoLabel' => $periodoLabel,
                    'relatorios' => ['Relatório consolidado'],
                    'nivelDesempenho' => $resumo,
                    'textoDesempenho' => null,
                    'blocosDesempenho' => $blocos,
                    'metricasDesempenho' => $avaliacao['metricas'] ?? [],
                ],
                $aluno->email,
                [$caminhoPdf]
            );
        } catch (Throwable $exc) {
            $this->registrarErro('Falha ao enviar o e-mail da contingência.', $aluno, $periodo, $exc);
            throw new RuntimeException('Não foi possível enviar o e-mail: '.$exc->getMessage(), 0, $exc);
        }

        $cco = MailHelper::enderecosCco($aluno->email);
        $sufixo = $cco === [] ? '' : ' (cco: '.implode(', ', $cco).')';
        $ok = "[{$aluno->nome}] E-mail enviado para {$aluno->email}{$sufixo}.";
        $log($ok);
        Log::channel('pdf-contingencia')->info($ok, $this->contexto($aluno, $periodo));
    }

    /**
     * @param  array{year_month: string, period: string, label?: string, inicio?: string, fim?: string}  $periodo
     */
    private function rotuloPeriodo(array $periodo): string
    {
        $inicio = (string) ($periodo['inicio'] ?? '');
        $fim = (string) ($periodo['fim'] ?? '');
        $ini = \DateTimeImmutable::createFromFormat('Y-m-d', $inicio);
        $fimDt = \DateTimeImmutable::createFromFormat('Y-m-d', $fim);
        if ($ini instanceof \DateTimeImmutable && $fimDt instanceof \DateTimeImmutable) {
            return $ini->format('d/m/Y').' a '.$fimDt->format('d/m/Y').' (período '.$periodo['period'].')';
        }

        return (string) ($periodo['label'] ?? $periodo['year_month'].' período '.$periodo['period']);
    }

    /**
     * @return array{blocos: list<array<string, mixed>>, metricas: array<string, mixed>, resumo: string|null}
     */
    private function avaliarPdf(Aluno $aluno, string $pdfPath, string $chavePeriodo): array
    {
        $dados = ['nome' => $aluno->nome];
        $sidecar = $this->caminhoMetricas($pdfPath);
        if (is_file($sidecar)) {
            $json = json_decode((string) file_get_contents($sidecar), true);
            $metricas = is_array($json['metricas'] ?? null) ? $json['metricas'] : [];
            $dados['dias_analisados'] = $metricas['dias_analisados'] ?? null;
            $dados['dias_estudados'] = $metricas['dias_estudados'] ?? null;
            $dados['dias_falhados'] = $metricas['dias_falhados'] ?? null;
            $dados['total_questoes'] = $metricas['total_questoes'] ?? null;
            $dados['percentual_acertos'] = $metricas['percentual_acertos'] ?? null;
            $dados['assuntos'] = is_array($metricas['assuntos'] ?? null) ? $metricas['assuntos'] : [];
        }

        return (new AvaliadorDesempenho)->avaliarRelatorio($dados, $aluno, $chavePeriodo);
    }

    private function caminhoMetricas(string $pdfPath): string
    {
        if (str_ends_with(strtolower($pdfPath), '.pdf')) {
            return substr($pdfPath, 0, -4).'.metricas.json';
        }

        return $pdfPath.'.metricas.json';
    }

    private function loggerComArquivo(?callable $logger): callable
    {
        return function (string $message) use ($logger): void {
            Log::channel('pdf-contingencia')->info($message);
            if ($logger !== null) {
                $logger($message);
            }
        };
    }

    /**
     * @param  array{year_month?: string, period?: string}  $periodo
     * @return array<string, mixed>
     */
    private function contexto(Aluno $aluno, array $periodo, ?Throwable $exc = null): array
    {
        $ctx = [
            'aluno_id' => $aluno->id,
            'aluno' => $aluno->nome,
            'email' => $aluno->email,
            'periodo' => ($periodo['year_month'] ?? '').'|'.($periodo['period'] ?? ''),
        ];
        if ($exc !== null) {
            $ctx['erro'] = $exc->getMessage();
        }

        return $ctx;
    }

    /**
     * @param  array{year_month?: string, period?: string}  $periodo
     */
    private function registrarErro(string $mensagem, Aluno $aluno, array $periodo, Throwable $exc): void
    {
        $contexto = $this->contexto($aluno, $periodo, $exc);
        Log::channel('pdf-contingencia')->error($mensagem, $contexto);
        Log::error($mensagem, $contexto);
    }
}
