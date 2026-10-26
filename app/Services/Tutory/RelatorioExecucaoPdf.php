<?php

namespace App\Services\Tutory;

use Dompdf\Dompdf;
use Dompdf\Options;

class RelatorioExecucaoPdf
{
    public function html(ResumoExecucaoRelatorio $resumo): string
    {
        $fim = $resumo->fim ?? $resumo->inicio;
        $e = static fn (string $valor): string => htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $indicadores = '';
        foreach ($resumo->indicadores() as $linha) {
            $indicadores .= '<tr><td>'.$e($linha['indicador']).'</td><td class="num">'.$linha['resultado'].'</td></tr>';
        }

        $alunos = '';
        foreach ($resumo->linhasDaExecucao() as $linha) {
            $alunos .= '<tr><td>'.$e($linha['nome']).'</td><td>'.$e($linha['pdf']).'</td><td>'.$e($linha['email']).'</td></tr>';
        }
        if ($alunos === '') {
            $alunos = '<tr><td colspan="3">Nenhum aluno entrou nesta execução.</td></tr>';
        }
        $tituloAlunos = $resumo->novatos === []
            ? 'Alunos com PDF gerado e situação do e-mail'
            : 'Alunos da execução e situação do e-mail';

        $divergencias = '';
        foreach ($resumo->divergencias() as $paragrafo) {
            $divergencias .= '<p>'.$e($paragrafo).'</p>';
        }
        if ($divergencias === '') {
            $divergencias = '<p>Nenhuma divergência entre a lista de PDFs e a etapa de e-mail.</p>';
        }

        $conclusao = '';
        foreach ($resumo->conclusao() as $paragrafo) {
            $conclusao .= '<p>'.$e($paragrafo).'</p>';
        }

        $teste = $resumo->teste ? '<p class="meta">Modo teste: apenas o aluno Mychel.</p>' : '';
        $nota = $resumo->nota !== null && $resumo->nota !== ''
            ? '<p class="meta">'.$e($resumo->nota).'</p>'
            : '';

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><style>
            @page { margin: 28px 32px; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
            h1 { font-size: 18px; color: #001D3D; margin: 0 0 8px; }
            h2 { font-size: 13px; color: #001D3D; border-bottom: 2px solid #BF8F00; padding-bottom: 3px; margin: 16px 0 8px; }
            .meta { margin: 0 0 3px; }
            table { width: 100%; border-collapse: collapse; margin-top: 4px; }
            th { background: #001D3D; color: #ffffff; text-align: left; padding: 5px 6px; font-size: 10px; }
            td { border-bottom: 1px solid #e4e4e4; padding: 4px 6px; vertical-align: top; }
            .num { text-align: right; font-weight: bold; width: 80px; }
            .rodape { margin-top: 18px; font-size: 9px; color: #333; }
            p { margin: 0 0 8px; line-height: 1.35; }
        </style></head><body>'
            .'<h1>Relatório de Execução — Tutory</h1>'
            .'<p class="meta">Data: '.$e($resumo->inicio->format('d/m/Y'))
            .' &nbsp; Período dos relatórios: '.$e($resumo->periodoRotulo).'</p>'
            .'<p class="meta">Início: '.$e($resumo->inicio->format('H:i:s'))
            .' &nbsp; Fim: '.$e($fim->format('H:i:s'))
            .' &nbsp; Duração: '.$e($resumo->duracao()).'</p>'
            .'<p class="meta">Fuso: '.$e($resumo->fuso).' &nbsp; PDF: '.$e($resumo->motor).'</p>'
            .$teste
            .$nota
            .'<h2>Resumo</h2>'
            .'<table><thead><tr><th>Indicador</th><th>Resultado</th></tr></thead><tbody>'
            .$indicadores
            .'</tbody></table>'
            .'<h2>'.$e($tituloAlunos).'</h2>'
            .'<table><thead><tr><th>Aluno</th><th>PDF</th><th>E-mail</th></tr></thead><tbody>'
            .$alunos
            .'</tbody></table>'
            .'<h2>Falhas / divergências na etapa de e-mail</h2>'
            .$divergencias
            .'<h2>Conclusão técnica</h2>'
            .$conclusao
            .'<p class="rodape">Chave de envio: '.$e($resumo->chaveEnvio).'<br>'
            .'Diretório dos PDFs: '.$e($resumo->pasta).'<br>'
            .'Log: '.$e($resumo->logArquivo ?? '—').'</p>'
            .'</body></html>';
    }

    public function salvar(ResumoExecucaoRelatorio $resumo): string
    {
        $diretorio = storage_path('app/tutory-execucao');
        if (! is_dir($diretorio)) {
            mkdir($diretorio, 0775, true);
        }

        $caminho = $diretorio.'/relatorio_execucao_tutory_'.$resumo->inicio->format('Y-m-d_His').'.pdf';
        file_put_contents($caminho, $this->bytes($resumo));

        return $caminho;
    }

    public function bytes(ResumoExecucaoRelatorio $resumo): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($resumo), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
