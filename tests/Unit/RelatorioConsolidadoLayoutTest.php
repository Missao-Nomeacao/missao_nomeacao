<?php

namespace Tests\Unit;

use App\Services\Tutory\RelatorioConsolidadoLayout;
use Tests\TestCase;

class RelatorioConsolidadoLayoutTest extends TestCase
{
    public function test_paleta_dos_graficos_de_barras_e_navy_e_dourado(): void
    {
        $this->assertSame(
            [RelatorioConsolidadoLayout::AZUL, RelatorioConsolidadoLayout::DOURADO],
            RelatorioConsolidadoLayout::paletaRitmo()
        );
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, RelatorioConsolidadoLayout::corSerieBarra('Horas brutas', 0));
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, RelatorioConsolidadoLayout::corSerieBarra('Horas líquidas', 1));
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, RelatorioConsolidadoLayout::corSerieBarra('Horas planejadas', 0));
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, RelatorioConsolidadoLayout::corSerieBarra('Horas estudadas', 1));
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, RelatorioConsolidadoLayout::corSerieBarra('Acertos', 0));
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, RelatorioConsolidadoLayout::corSerieBarra('Erros', 1));
    }

    public function test_ordem_fixa_das_secoes(): void
    {
        $this->assertSame([
            'Seu desempenho',
            'Ritmo de estudos',
            'Painel de Insights',
            'Desempenho em questões',
            'Performance por assunto',
            'Revisões no período',
            'Histórico completo',
        ], RelatorioConsolidadoLayout::SECOES);
    }

    public function test_cor_do_percentual_so_no_indicador(): void
    {
        $this->assertSame(RelatorioConsolidadoLayout::VERMELHO, RelatorioConsolidadoLayout::corPercentual(0));
        $this->assertSame(RelatorioConsolidadoLayout::VERMELHO, RelatorioConsolidadoLayout::corPercentual(65));
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, RelatorioConsolidadoLayout::corPercentual(66));
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, RelatorioConsolidadoLayout::corPercentual(79.9));
        $this->assertSame(RelatorioConsolidadoLayout::VERDE, RelatorioConsolidadoLayout::corPercentual(80));
        $this->assertSame(RelatorioConsolidadoLayout::VERDE, RelatorioConsolidadoLayout::corPercentual(100));
    }

    public function test_secao_vazia_e_omitida(): void
    {
        $this->assertSame('', RelatorioConsolidadoLayout::secao('Ritmo de estudos', '', ''));
        $html = RelatorioConsolidadoLayout::secao('Seu desempenho', 'Intro', '<p>ok</p>');
        $this->assertStringContainsString('Seu desempenho', $html);
        $this->assertStringContainsString('Intro', $html);
        $this->assertStringContainsString('mn-sec-title', $html);
    }

    public function test_insights_nao_duplicam_titulo_e_preservam_texto(): void
    {
        $html = RelatorioConsolidadoLayout::insights([
            'Painel de Insights',
            'Média de 02:00',
            'A matéria mais estudada foi Direito Constitucional.',
            'SEDES - PORTUGUÊS foi a matéria que você menos estudou',
            '908 exercícios | 765 acertos | 84%',
        ]);
        $this->assertStringNotContainsString('Painel de Insights', $html);
        $this->assertStringNotContainsString('foi a matéria', $html);
        $this->assertStringNotContainsString('A matéria mais estudada foi', $html);
        $this->assertStringContainsString('mn-kpis', $html);
        $this->assertStringContainsString('kpi-value', $html);
        $this->assertStringContainsString('MÉDIA DIÁRIA', $html);
        $this->assertStringContainsString('02:00', $html);
        $this->assertStringContainsString('MATÉRIA MAIS ESTUDADA', $html);
        $this->assertStringContainsString('Direito Constitucional', $html);
        $this->assertStringContainsString('MATÉRIA MENOS ESTUDADA', $html);
        $this->assertStringContainsString('SEDES - PORTUGUÊS', $html);
        $this->assertStringContainsString('EXERCÍCIOS REALIZADOS', $html);
        $this->assertStringContainsString('908', $html);
        $this->assertStringContainsString('ACERTOS', $html);
        $this->assertStringContainsString('765', $html);
        $this->assertStringContainsString('TAXA DE ACERTOS', $html);
        $this->assertStringContainsString('84%', $html);
        $this->assertSame(6, substr_count($html, 'class="kpi"') + substr_count($html, 'class="kpi '));
        $this->assertSame(2, preg_match_all('/<tr><td class="kpi /', $html));
        $this->assertStringContainsString('kpi-label', $html);
        $this->assertStringContainsString('kpi-long', $html);
        $this->assertStringContainsString('kpi-num', $html);
        $this->assertStringContainsString('kpi-text', $html);
        $this->assertStringContainsString('kpi-v-a', $html);
        $this->assertStringContainsString('kpi-stack', $html);
        $this->assertStringContainsString('class="kpi kpi-num"', $html);
        $this->assertStringContainsString('class="kpi kpi-text"', $html);
        $this->assertStringNotContainsString('kpi-inner', $html);
        $this->assertStringNotContainsString('mn-insight-block', $html);
    }

    public function test_tabela_aplica_cor_so_no_percentual(): void
    {
        $html = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Taxa de Acertos'],
            [
                ['Direito Administrativo', 'Improbidade administrativa', '55%'],
                ['Direito Constitucional', 'Organização do Estado', '82%'],
            ],
            ['numeric' => [2], 'percent_col' => 2]
        );
        $this->assertStringContainsString('Improbidade administrativa', $html);
        $this->assertStringContainsString('color:'.RelatorioConsolidadoLayout::VERMELHO, $html);
        $this->assertStringContainsString('color:'.RelatorioConsolidadoLayout::VERDE, $html);
        $this->assertStringNotContainsString('background:'.RelatorioConsolidadoLayout::VERMELHO.'">Improbidade', $html);
        $this->assertStringNotContainsString('mn-table-band', $html);
        $this->assertStringContainsString('class="z"', $html);
        $this->assertMatchesRegularExpression('/<td class="num mn-pct">/', $html);
        $this->assertMatchesRegularExpression('/<th class="num mn-pct" style="width:\d+%">Taxa de Acertos<\/th>/', $html);
        $this->assertStringContainsString('class="mn-c-assunto"', $html);
        $this->assertStringContainsString('class="mn-assunto"', $html);
        $this->assertStringNotContainsString('…', $html);
        $this->assertStringNotContainsString('ellipsis', $html);
    }

    public function test_tabela_destina_espaco_restante_ao_assunto(): void
    {
        $performance = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Taxa de Acertos'],
            [
                ['SEDES - ADMINISTRAÇÃO DE RECURSOS DE MATERIAIS', 'Improbidade administrativa e o dever de probidade na administração pública', '55%'],
            ],
            ['numeric' => [2], 'percent_col' => 2]
        );
        $larguras = $this->largurasColgroup($performance);
        $this->assertSame(['disciplina', 'assunto', 'pct'], array_keys($larguras));
        $this->assertGreaterThan($larguras['disciplina'], $larguras['assunto']);
        $this->assertGreaterThan($larguras['pct'], $larguras['assunto']);
        $this->assertLessThan($larguras['disciplina'], $larguras['pct']);
        $this->assertSame(100, array_sum($larguras));

        $revisoes = RelatorioConsolidadoLayout::tabela(
            ['Disciplina revisada', 'Assunto revisado', 'Revisões no período'],
            [['Direito Penal', 'Teoria do crime', '3']],
            ['numeric' => [2]]
        );
        $largurasRev = $this->largurasColgroup($revisoes);
        $this->assertGreaterThan($largurasRev['disciplina'], $largurasRev['assunto']);
        $this->assertGreaterThan($largurasRev['num'], $largurasRev['assunto']);
        $this->assertStringContainsString('Teoria do crime', $revisoes);
        $this->assertStringContainsString('class="num mn-qtd"', $revisoes);

        $historico = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Modalidade', 'Horas'],
            [[
                'SEDES - ADMINISTRAÇÃO DE RECURSOS DE MATERIAIS',
                'Inventário e controle de estoque de materiais permanentes e de consumo',
                'Cadernos de Exercícios (Múltipla Escolha)',
                '01:12:40',
            ]],
            ['numeric' => [3]]
        );
        $largurasHist = $this->largurasColgroup($historico);
        $this->assertGreaterThan($largurasHist['disciplina'], $largurasHist['assunto']);
        $this->assertGreaterThan($largurasHist['modalidade'], $largurasHist['assunto']);
        $this->assertGreaterThan($largurasHist['horas'], $largurasHist['assunto']);
        $this->assertLessThanOrEqual($largurasHist['modalidade'], $largurasHist['horas']);
        $this->assertStringContainsString('class="num mn-horas"', $historico);
        $this->assertStringContainsString('01:12:40', $historico);
        $this->assertStringContainsString('Cadernos de Exercícios (Múltipla Escolha)', $historico);
        $this->assertStringContainsString('class="mn-mod"', $historico);
    }

    public function test_corpo_das_tabelas_alinha_sem_alterar_cabecalho(): void
    {
        $historico = RelatorioConsolidadoLayout::tabela(
            ['Disciplina', 'Assunto', 'Modalidade', 'Horas'],
            [['Direito Penal', 'Teoria do crime', 'Videoaula', '01:12:40']],
            ['numeric' => [3]]
        );
        $this->assertMatchesRegularExpression('/<th class="mn-disc" style="width:\d+%">Disciplina<\/th>/', $historico);
        $this->assertStringContainsString('<td class="mn-disc">Direito Penal</td>', $historico);
        $this->assertStringContainsString('<td class="mn-assunto">Teoria do crime</td>', $historico);
        $this->assertStringContainsString('<td class="mn-mod">Videoaula</td>', $historico);
        $this->assertStringContainsString('<td class="num mn-horas">01:12:40</td>', $historico);

        $css = RelatorioConsolidadoLayout::css("'Inter'", '');
        $this->assertStringContainsString('.mn-table th{background:#001D3D; color:#ffffff; font-weight:600; font-size:9pt; letter-spacing:0.03em; text-transform:uppercase; padding:9px 11px; text-align:left; vertical-align:middle; white-space:normal;}', $css);
        $this->assertDoesNotMatchRegularExpression('/\.mn-table th\{[^}]*text-align:center/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.mn-table tbody td\{[^}]*padding-top:\d{2}/', $css);
    }

    public function test_css_tabelas_e_espacamentos_seguem_o_comando(): void
    {
        $css = RelatorioConsolidadoLayout::css("'Inter'", '');
        $this->assertStringContainsString('@page{margin:34mm 16mm 18mm 16mm;}', $css);
        $this->assertStringContainsString('.mn-sec{margin:0 0 28px;}', $css);
        $this->assertStringContainsString('.mn-sec-intro{font-size:10.5pt; font-weight:400; color:#4B5563; margin:7px 0 0;', $css);
        $this->assertStringContainsString('.mn-sec-body{margin-top:14px;}', $css);
        $this->assertStringContainsString('border-spacing:14px 0', $css);
        $this->assertStringContainsString('.mn-kpis td.kpi{background:#ffffff; border:1.5pt solid #BF8F00; border-radius:9px; padding:18px 18px;', $css);
        $this->assertStringContainsString('.mn-chart{margin:8px 0 16px;', $css);
        $this->assertStringContainsString('.mn-table{width:100%; max-width:100%; border-collapse:collapse; font-size:9pt; table-layout:fixed; margin:0; border:1.25pt solid #001D3D;}', $css);
        $this->assertStringContainsString('padding:9px 11px', $css);
        $this->assertStringContainsString('height:auto', $css);
        $this->assertStringContainsString('.mn-table tr{page-break-inside:avoid;', $css);
        $this->assertStringContainsString('.mn-table tbody tr:first-child,.mn-table tbody tr:nth-child(2){page-break-before:avoid;}', $css);
        $this->assertStringContainsString('white-space:nowrap', $css);
        $this->assertStringContainsString('.mn-table td.mn-horas{white-space:nowrap;}', $css);
        $this->assertStringContainsString('.mn-table th,.mn-table td{height:auto; border:1.25pt solid #001D3D;}', $css);
        $this->assertStringContainsString('.mn-table td.mn-disc,.mn-table td.mn-mod,.mn-table td.mn-horas,.mn-table td.num,.mn-table td.mn-assunto{text-align:center; vertical-align:middle;}', $css);
        $this->assertStringContainsString('.mn-table td.mn-pct{text-align:center;}', $css);
        $this->assertStringContainsString('.mn-table th.mn-disc,.mn-table th.mn-assunto,.mn-table th.mn-mod,.mn-table th.mn-horas,.mn-table th.mn-qtd,.mn-table th.mn-pct{text-align:center; vertical-align:middle;}', $css);
        $this->assertStringContainsString('line-height:1.15', $css);
        $this->assertStringContainsString('.kpi-label{font-size:9.5pt; font-weight:600;', $css);
        $this->assertStringContainsString('.kpi-value{font-size:21pt; font-weight:700;', $css);
        $this->assertStringContainsString('.kpi-long{font-size:11.5pt; font-weight:700; line-height:1.20;', $css);
        $this->assertStringContainsString('.kpi-inner{width:100%; border-collapse:collapse;', $css);
        $this->assertStringContainsString('.kpi-row{display:table; width:100%; table-layout:fixed;', $css);
        $this->assertStringContainsString('.kpi-row .kpi-label,.kpi-row .kpi-value{display:table-cell; vertical-align:middle;}', $css);
        $this->assertStringContainsString('.kpi-num .kpi-label{display:block; width:auto;', $css);
        $this->assertStringContainsString('.kpi-num .kpi-v-a{font-size:24pt;}', $css);
        $this->assertStringContainsString('.kpi-num .kpi-v-b{font-size:22pt;}', $css);
        $this->assertStringContainsString('.kpi-num .kpi-v-c{font-size:20pt;}', $css);
        $this->assertStringContainsString('.kpi-num .kpi-v-d{font-size:18pt;}', $css);
        $this->assertStringContainsString('.kpi-num .kpi-v-e{font-size:16pt;}', $css);
        $this->assertStringContainsString('.kpi-compact .kpi-label{font-size:8pt;', $css);
        $this->assertStringContainsString('.kpi-compact .kpi-v-a{font-size:20pt;}', $css);
        $this->assertStringContainsString('.kpi-compact .kpi-v-b{font-size:18pt;}', $css);
        $this->assertStringNotContainsString('.kpi-num .kpi-label{width:40%;', $css);
        $this->assertStringNotContainsString('.kpi-num .kpi-v-a{font-size:52pt;}', $css);
        $this->assertStringNotContainsString('.kpi-compact .kpi-value{font-size:18pt;}', $css);
        $this->assertStringContainsString('.kpi-text .kpi-label{margin:0 0 6px; line-height:1.20;}', $css);
        $this->assertStringContainsString('.mn-table th{background:#001D3D; color:#ffffff; font-weight:600; font-size:9pt; letter-spacing:0.03em; text-transform:uppercase; padding:9px 11px; text-align:left; vertical-align:middle; white-space:normal;}', $css);
        $this->assertStringNotContainsString('width:18%', $css);
        $this->assertStringNotContainsString('width:1%', $css);
        $this->assertStringNotContainsString('text-overflow:ellipsis', $css);
        $this->assertStringNotContainsString('mn-table-band', $css);
        $this->assertStringNotContainsString('padding-top:56px', $css);
        $this->assertStringNotContainsString('#F5F5F5', $css);
        $this->assertStringNotContainsString('text-align:justify', $css);
        $this->assertDoesNotMatchRegularExpression('/html,body\{[^}]*margin:0/', $css);
    }

    public function test_css_nao_usa_border_radius_nem_fundo_cinza_de_rodape(): void
    {
        $css = RelatorioConsolidadoLayout::css("'Inter'", '');
        $this->assertStringContainsString('@page{margin:34mm 16mm 18mm 16mm;}', $css);
        $this->assertStringContainsString('background:#ffffff', $css);
        $this->assertStringNotContainsString('#F5F5F5', $css);
        $this->assertStringContainsString('border-radius:9px', $css);
        $this->assertStringNotContainsString('Montserrat', $css);
        $this->assertStringNotContainsString('kpi-hot', $css);
        $this->assertDoesNotMatchRegularExpression('/html,body\{[^}]*margin:0/', $css);
    }

    public function test_cards_se_reorganizam_conforme_a_quantidade(): void
    {
        $quatro = RelatorioConsolidadoLayout::cards([
            ['label' => 'A', 'value' => '1'],
            ['label' => 'B', 'value' => '2'],
            ['label' => 'C', 'value' => '3'],
            ['label' => 'D', 'value' => '4'],
        ]);
        $this->assertSame(1, substr_count($quatro, '<table class="mn-kpis"><tbody><tr>'));
        $tres = RelatorioConsolidadoLayout::cards([
            ['label' => 'A', 'value' => '1'],
            ['label' => 'B', 'value' => '2'],
            ['label' => 'C', 'value' => '3'],
        ]);
        $this->assertSame(1, substr_count($tres, '<table class="mn-kpis"><tbody><tr>'));
        $this->assertSame(3, substr_count($tres, '<td class="kpi '));
        $this->assertStringNotContainsString('kpi-hot', $quatro);
        $this->assertStringNotContainsString('kpi-hot', $tres);
        $this->assertSame(4, substr_count($quatro, 'kpi-num'));
        $this->assertStringContainsString('kpi-compact', $quatro);
        $this->assertStringContainsString('kpi-v-a', $quatro);
        $this->assertStringNotContainsString('kpi-inner', $quatro);
        $texto = RelatorioConsolidadoLayout::cards([
            ['label' => 'Matéria mais estudada', 'value' => 'Direito Constitucional'],
        ]);
        $this->assertStringContainsString('kpi-text', $texto);
        $this->assertStringContainsString('kpi-stack', $texto);
        $this->assertStringContainsString('kpi-long', $texto);
        $this->assertStringNotContainsString('kpi-inner', $texto);
        $this->assertStringNotContainsString('kpi-num', $texto);
        $this->assertTrue(RelatorioConsolidadoLayout::eValorNumerico('32h'));
        $this->assertTrue(RelatorioConsolidadoLayout::eValorNumerico('78,1%'));
        $this->assertTrue(RelatorioConsolidadoLayout::eValorNumerico('03:16'));
        $this->assertTrue(RelatorioConsolidadoLayout::eValorNumerico('979:00'));
        $this->assertTrue(RelatorioConsolidadoLayout::eValorNumerico('0%'));
        $this->assertFalse(RelatorioConsolidadoLayout::eValorNumerico('Direito Constitucional'));
        $this->assertSame('kpi-v-a', RelatorioConsolidadoLayout::classeTamanhoValor('0%'));
        $this->assertSame('kpi-v-a', RelatorioConsolidadoLayout::classeTamanhoValor('84%'));
        $this->assertSame('kpi-v-a', RelatorioConsolidadoLayout::classeTamanhoValor('282'));
        $this->assertSame('kpi-v-c', RelatorioConsolidadoLayout::classeTamanhoValor('83,7%'));
        $this->assertSame('kpi-v-c', RelatorioConsolidadoLayout::classeTamanhoValor('00:00'));
        $this->assertSame('kpi-v-d', RelatorioConsolidadoLayout::classeTamanhoValor('979:00'));
    }

    public function test_cards_numericos_maximizam_o_valor_abaixo_do_rotulo(): void
    {
        $html = RelatorioConsolidadoLayout::cards([
            ['label' => 'Total de horas', 'value' => '979:00'],
            ['label' => '% de acertos', 'value' => '84%'],
            ['label' => 'Progresso geral', 'value' => '0%'],
        ]);
        $this->assertStringContainsString('<div class="kpi-label">Total de horas</div><div class="kpi-value kpi-v-d">979:00</div>', $html);
        $this->assertStringContainsString('<div class="kpi-label">% de acertos</div><div class="kpi-value kpi-v-a">84%</div>', $html);
        $this->assertStringContainsString('<div class="kpi-label">Progresso geral</div><div class="kpi-value kpi-v-a">0%</div>', $html);
        $this->assertStringNotContainsString('kpi-inner', $html);
        $this->assertStringNotContainsString('kpi-text', $html);
    }

    public function test_cabecalho_e_hierarquia_de_abertura(): void
    {
        $this->assertSame('MISSÃO NOMEAÇÃO', RelatorioConsolidadoLayout::textoCabecalhoEsquerdo());
        $this->assertSame(
            'AGOSTO - PERÍODO 1',
            RelatorioConsolidadoLayout::rotuloPeriodo('1', new \DateTimeImmutable('2026-08-10'))
        );
        $this->assertSame(
            'SETEMBRO - PERÍODO 2',
            RelatorioConsolidadoLayout::rotuloPeriodo('2', new \DateTimeImmutable('2026-09-20'))
        );
        $this->assertSame(
            RelatorioConsolidadoLayout::INTRO_HISTORICO,
            'Confira o histórico completo de horas cronometradas no período.'
        );
        $this->assertSame(
            RelatorioConsolidadoLayout::TITULO_GRAFICO_PLANEJADAS,
            'Horas planejadas × horas estudadas'
        );
        $this->assertStringNotContainsString('(brutas)', RelatorioConsolidadoLayout::TITULO_GRAFICO_PLANEJADAS);

        $html = RelatorioConsolidadoLayout::alunoNome('Giovanna')
            .RelatorioConsolidadoLayout::secao('Seu desempenho', 'Curso X', '<p>ok</p>', 'mn-sec-keep');
        $this->assertStringContainsString('GIOVANNA', $html);
        $posNome = strpos($html, 'GIOVANNA');
        $posTitulo = strpos($html, 'Seu desempenho');
        $this->assertNotFalse($posNome);
        $this->assertNotFalse($posTitulo);
        $this->assertLessThan($posTitulo, $posNome);
        $this->assertStringContainsString('mn-sec-keep', $html);
        $this->assertStringContainsString('Curso X', $html);
    }

    /**
     * @return array<string, int>
     */
    private function largurasColgroup(string $html): array
    {
        preg_match_all('/<col class="mn-c-([a-z]+)"[^>]*width:(\d+)%/', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $col) {
            $out[$col[1]] = (int) $col[2];
        }

        return $out;
    }
}
