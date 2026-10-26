<?php

namespace App\Services\Tutory;

use Dompdf\Dompdf;

/**
 * Gera um PDF de amostra (mesma casca do relatório quinzenal) para o preview do admin.
 */
final class PdfPreview
{
    public function gerar(?string $chaveFonte = null): string
    {
        $chave = PdfFontes::normalizar($chaveFonte);
        if (in_array($chave, ['times', 'courier'], true)) {
            $chave = PdfFontes::diretorioInter() !== null ? 'inter' : 'dejavu';
        }
        $fontCss = PdfFontes::css($chave);
        $css = RelatorioConsolidadoLayout::css($fontCss, '', PdfFontes::fontFaceCss($chave));
        $rotulo = RelatorioConsolidadoLayout::rotuloPeriodo('2', new \DateTimeImmutable('2026-09-16'));

        $cards = RelatorioConsolidadoLayout::cards([
            ['label' => 'Total de Horas', 'value' => '638:45'],
            ['label' => 'Dias de estudo', 'value' => '234'],
            ['label' => '% de acertos', 'value' => '80%'],
            ['label' => 'Progresso geral', 'value' => '23%'],
        ], 4, true);

        $ritmo = RelatorioConsolidadoLayout::grafico(
            RelatorioConsolidadoLayout::TITULO_GRAFICO_PLANEJADAS,
            '<p class="empty">Gráfico omitido neste preview de fonte.</p>',
            RelatorioConsolidadoLayout::LEGENDA_HORAS_ESTUDADAS
        );

        $insights = RelatorioConsolidadoLayout::insights([
            'Média diária 02:39',
            'A matéria mais estudada foi PMSC - Português.',
            'A matéria menos estudada foi PMSC - Direito Constitucional.',
            'Exercícios realizados 319',
            'Acertos 254',
            'Taxa de acertos 79,6%',
        ]);

        $questoes = RelatorioConsolidadoLayout::cards([
            ['label' => 'Questões realizadas', 'value' => '319'],
            ['label' => 'Acertos', 'value' => '254'],
            ['label' => 'Percentual de acertos', 'value' => '79,6%'],
        ]);

        $assuntos = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Taxa de Acertos'],
            [
                ['PMSC - Direito Penal Militar', 'Suspensão condicional da pena', '85,0%'],
                ['PMSC - Direito Processual Penal', 'Título II: Inquérito policial (art. 4º ao 23)', '82,7%'],
                ['PMSC - Direito Processual Penal', 'Título III: Ação penal', '75,8%'],
                ['PMSC - Legislação de Trânsito', 'Lei nº 9.503/1997: Dos veículos (art. 96 ao 117)', '75,0%'],
            ],
            ['numeric' => [2], 'percent_col' => 2]
        );

        $revisoes = RelatorioConsolidadoLayout::tabela(
            ['Disciplina revisada', 'Assunto revisado', 'Revisões no período'],
            [
                ['PMSC - Direito Penal Geral', 'Crime: fato típico', '2'],
                ['PMSC - Português', 'Compreensão e interpretação de textos', '1'],
            ],
            ['numeric' => [2]]
        );

        $historico = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Modalidade', 'Horas'],
            [
                ['PMSC - Português', 'Compreensão e interpretação de textos', 'Estudo (coach)', '07:51:35'],
                ['PMSC - Direito Penal Especial', 'Crimes contra o patrimônio: furto, roubo e extorsão (arts. 155 a 160)', 'Estudo', '04:20:00'],
                ['PMSC - Direito Processual Penal', 'Título III: Ação penal', 'Exercício', '03:37:00'],
                ['PMSC - Legislação Especial', 'Estatuto do Idoso (Lei nº 10.741/2003)', 'Estudo por vídeos', '00:45:07'],
            ],
            ['numeric' => [3]]
        );

        $secoes = RelatorioConsolidadoLayout::alunoNome('Mychel')
            .RelatorioConsolidadoLayout::secao(
                'Seu desempenho',
                'CFP · PMSC',
                $cards,
                'mn-sec-keep'
            )
            .RelatorioConsolidadoLayout::secao('Ritmo de estudos', '', $ritmo)
            .RelatorioConsolidadoLayout::secao('Painel de Insights', '', $insights, 'mn-sec-insights')
            .RelatorioConsolidadoLayout::secao('Desempenho em questões', 'Confira o seu desempenho de questões no período', $questoes)
            .RelatorioConsolidadoLayout::secao('Performance por assunto', 'Amostra de texto com acentuação: ação, ciência, constituição, órgão.', $assuntos, 'mn-sec-table')
            .RelatorioConsolidadoLayout::secao('Revisões no período', '', $revisoes, 'mn-sec-table')
            .RelatorioConsolidadoLayout::secao(
                'Histórico completo',
                RelatorioConsolidadoLayout::INTRO_HISTORICO,
                $historico,
                'mn-sec-table'
            );

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
{$css}
</style></head><body>
{$secoes}
</body></html>
HTML;

        $dompdf = new Dompdf(PdfFontes::opcoesDompdf($chave));
        PdfFontes::aplicarNoDompdf($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        RelatorioConsolidadoLayout::aplicarCabecalhoRodape($dompdf, $rotulo);

        $bytes = $dompdf->output() ?? '';
        if ($bytes === '' || strlen($bytes) < 500) {
            return $bytes;
        }

        $tmp = sys_get_temp_dir().'/mn-preview-'.uniqid('', true).'.pdf';
        file_put_contents($tmp, $bytes);
        try {
            RelatorioPdfCapas::aplicar($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
