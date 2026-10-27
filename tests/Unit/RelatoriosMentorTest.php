<?php

namespace Tests\Unit;

use App\Services\Tutory\CoachReportDownloader;
use App\Services\Tutory\RelatorioConsolidadoLayout;
use App\Services\Tutory\RelatorioPdfCapas;
use App\Services\Tutory\RelatorioQuinzenalPdf;
use ReflectionClass;
use Tests\TestCase;

class RelatoriosMentorTest extends TestCase
{
    public function test_relatorios_cobre_todos_os_modelos_do_menu_do_mentor(): void
    {
        $ref = new ReflectionClass(CoachReportDownloader::class);
        $relatorios = $ref->getConstant('RELATORIOS');

        $this->assertSame(
            ['desempenho', 'aluno', 'horas-liquidas', 'questoes', 'progresso'],
            array_column($relatorios, 'model')
        );
        $this->assertSame(
            ['Desempenho', 'Estudos', 'Horas Líquidas', 'Desempenho em Questões', 'Progresso do plano'],
            array_column($relatorios, 'nome')
        );
    }

    public function test_extrai_modelo_com_hifen_do_nome_do_arquivo(): void
    {
        $meta = $this->chamar('extrairMetaDoArquivoPdf', 'relatorio_horas-liquidas_20260815_1200_Giovanna_1.pdf');

        $this->assertSame('horas-liquidas', $meta['model']);
        $this->assertSame('Giovanna', $meta['nome']);
    }

    public function test_extrai_modelo_consolidado_do_nome_do_arquivo(): void
    {
        $meta = $this->chamar('extrairMetaDoArquivoPdf', 'relatorio_consolidado_20260818_2230_Giovanna_1.pdf');

        $this->assertSame('consolidado', $meta['model']);
        $this->assertSame('Giovanna', $meta['nome']);
    }

    public function test_encontra_apenas_o_pdf_consolidado_do_aluno(): void
    {
        $pasta = sys_get_temp_dir().'/tutory-relatorios-'.uniqid('', true);
        mkdir($pasta, 0775, true);

        try {
            foreach (['desempenho', 'aluno', 'horas-liquidas', 'questoes', 'progresso'] as $model) {
                file_put_contents(
                    $pasta.'/relatorio_'.$model.'_20260815_1200_Giovanna_1.pdf',
                    '%PDF-1.4 fake'
                );
            }
            file_put_contents(
                $pasta.'/relatorio_consolidado_20260818_2230_Giovanna_1.pdf',
                '%PDF-1.4 consolidado'
            );

            $downloader = new CoachReportDownloader('1', static function (): void {});
            $ref = new ReflectionClass($downloader);
            $ref->getProperty('pastaDownload')->setValue($downloader, $pasta);

            $pdfs = $ref->getMethod('encontrarPdfsAluno')->invoke($downloader, 'Giovanna');
            $arquivos = array_map('basename', $pdfs);

            $this->assertCount(1, $pdfs);
            $this->assertSame(['relatorio_consolidado_20260818_2230_Giovanna_1.pdf'], $arquivos);
        } finally {
            foreach (scandir($pasta) ?: [] as $arquivo) {
                if ($arquivo === '.' || $arquivo === '..') {
                    continue;
                }
                @unlink($pasta.'/'.$arquivo);
            }
            @rmdir($pasta);
        }
    }

    public function test_script_de_composicao_extrai_as_secoes_pedidas(): void
    {
        $script = base_path('scripts/tutory-compose-pdf.mjs');
        $this->assertFileExists($script);
        $src = (string) file_get_contents($script);

        $this->assertStringContainsString('.main-header-card', $src);
        $this->assertStringContainsString('.metrics-grid', $src);
        $this->assertStringContainsString('#tabela_revisoes', $src);
        $this->assertStringContainsString('chart_line_comparativo', $src);
        $this->assertStringContainsString('#tabela_horas_liquidas', $src);
        $this->assertStringContainsString('chart_questoes_dia', $src);
        $this->assertStringContainsString('#tabela_questoes', $src);
        $this->assertStringContainsString('chart_horas_diarias', $src);
        $this->assertStringContainsString('labelHoursOnChartVertices', $src);
        $this->assertStringContainsString('.insights-panel', $src);
        $this->assertStringContainsString('mn-sec-title', $src);
        $this->assertStringContainsString('Seu desempenho', $src);
        $this->assertStringContainsString('Ritmo de estudos', $src);
        $this->assertStringContainsString('Painel de Insights', $src);
        $this->assertStringContainsString('Histórico completo', $src);
        $this->assertStringContainsString('--url-desempenho', $src);
        $this->assertStringContainsString('--rotulo-periodo', $src);
        $this->assertStringNotContainsString('chart_horas_estudo', $src);
        $this->assertStringNotContainsString('chart_performance', $src);
        $this->assertStringNotContainsString("htmlFromHeading('h2.section-5')", $src);
        $this->assertStringNotContainsString('extracted.desempenho.twoCol', $src);
        $this->assertStringNotContainsString('extracted.aluno.estudos', $src);
        $this->assertStringNotContainsString('chart_panorama', $src);
        $this->assertStringNotContainsString('chart_bolha_questoes', $src);
        $this->assertStringNotContainsString('chart_pie_horas_disciplina', $src);
    }

    public function test_binario_node_nao_quebra_quando_o_path_e_minimo(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $bin = $ref->getMethod('binarioNode')->invoke($downloader);

        $this->assertTrue($bin === null || is_string($bin));
        if (is_string($bin)) {
            $this->assertFileExists($bin);
        }
    }

    public function test_sem_node_o_motor_do_pdf_e_dompdf(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $bin = $ref->getMethod('binarioNode')->invoke($downloader);
        $pode = $ref->getMethod('podeUsarPuppeteer')->invoke($downloader);

        if ($bin === null) {
            $this->assertFalse($pode);
        } else {
            $this->assertTrue(is_bool($pode));
        }
    }

    public function test_motor_padrao_e_dompdf_sem_npm(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);

        $this->assertFalse($ref->getMethod('podeUsarPuppeteer')->invoke($downloader));
        $this->assertStringContainsString(
            'dompdf',
            strtolower((string) $ref->getMethod('motivoSemPuppeteer')->invoke($downloader))
        );
    }

    public function test_sem_pacote_puppeteer_nao_usa_o_compositor(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $instalado = $ref->getMethod('pacotePuppeteerInstalado')->invoke($downloader);
        $esperado = is_file(base_path('node_modules/puppeteer/package.json'))
            || is_file(base_path('node_modules/puppeteer-core/package.json'));

        $this->assertSame($esperado, $instalado);
        if (! $instalado) {
            $this->assertFalse($ref->getMethod('podeUsarPuppeteer')->invoke($downloader));
            $motivo = (string) $ref->getMethod('motivoSemPuppeteer')->invoke($downloader);
            $this->assertNotSame('', $motivo);
        }
    }

    public function test_periodo_2_no_dia_1_usa_a_quinzena_do_mes_anterior(): void
    {
        $downloader = new CoachReportDownloader('2', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $iso = $ref->getMethod('datasPeriodoIso');
        $br = $ref->getMethod('datasPeriodoBr');
        $mes = $ref->getMethod('mesDoPeriodo');

        $primeiro = new \DateTimeImmutable('2026-09-01');
        $this->assertSame(['2026-08-16', '2026-08-31'], $iso->invoke($downloader, $primeiro));
        $this->assertSame(['16/08/2026', '31/08/2026'], $br->invoke($downloader, $primeiro));
        $this->assertSame(
            '2026-08-01',
            $mes->invoke($downloader, $primeiro)->format('Y-m-d')
        );
        $this->assertSame(
            'AGOSTO - PERÍODO 2',
            RelatorioConsolidadoLayout::rotuloPeriodo('2', $mes->invoke($downloader, $primeiro))
        );
    }

    public function test_periodo_2_a_partir_do_dia_16_usa_o_mes_corrente(): void
    {
        $downloader = new CoachReportDownloader('2', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $iso = $ref->getMethod('datasPeriodoIso');

        $this->assertSame(
            ['2026-08-16', '2026-08-31'],
            $iso->invoke($downloader, new \DateTimeImmutable('2026-08-31'))
        );
        $this->assertSame(
            ['2026-09-16', '2026-09-30'],
            $iso->invoke($downloader, new \DateTimeImmutable('2026-09-16'))
        );
    }

    public function test_referencia_mes_fixa_o_periodo_2_sem_recuar(): void
    {
        $downloader = new CoachReportDownloader(
            '2',
            static function (): void {},
            false,
            new \DateTimeImmutable('2026-09-01'),
        );
        $ref = new ReflectionClass($downloader);
        $iso = $ref->getMethod('datasPeriodoIso');
        $mes = $ref->getMethod('mesDoPeriodo');

        $this->assertSame(['2026-09-16', '2026-09-30'], $iso->invoke($downloader));
        $this->assertSame('2026-09-01', $mes->invoke($downloader)->format('Y-m-d'));
        $this->assertSame(
            'SETEMBRO - PERÍODO 2',
            RelatorioConsolidadoLayout::rotuloPeriodo('2', $mes->invoke($downloader))
        );
    }

    public function test_periodo_1_nao_recua_o_mes_antes_do_dia_16(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $iso = $ref->getMethod('datasPeriodoIso');

        $this->assertSame(
            ['2026-09-01', '2026-09-15'],
            $iso->invoke($downloader, new \DateTimeImmutable('2026-09-01'))
        );
        $this->assertSame(
            ['2026-08-01', '2026-08-15'],
            $iso->invoke($downloader, new \DateTimeImmutable('2026-08-16'))
        );
    }

    public function test_nao_aplica_marca_dagua_diagonal(): void
    {
        $php = (string) file_get_contents((new ReflectionClass(CoachReportDownloader::class))->getFileName());
        $this->assertStringNotContainsString('aplicarMarcaDaguaPdf', $php);
        $this->assertStringContainsString('use Illuminate\\Support\\Facades\\Http;', $php);
        $this->assertStringContainsString('RelatorioQuinzenalPdf::gerar', $php);
        $pdf = (string) file_get_contents((new ReflectionClass(RelatorioQuinzenalPdf::class))->getFileName());
        $this->assertStringContainsString('RelatorioPdfCapas::aplicar', $pdf);
        $this->assertSame('AGOSTO - PERÍODO 1', RelatorioConsolidadoLayout::rotuloPeriodo('1', new \DateTimeImmutable('2026-08-10')));
        $this->assertSame('AGOSTO - PERÍODO 2', RelatorioConsolidadoLayout::rotuloPeriodo('2', new \DateTimeImmutable('2026-08-20')));
        $this->assertSame('MISSÃO NOMEAÇÃO', RelatorioConsolidadoLayout::textoCabecalhoEsquerdo());
        $this->assertStringNotContainsString('MISSÃO NOMEAÇÃO •', RelatorioConsolidadoLayout::textoCabecalhoEsquerdo());
    }

    public function test_deploy_nao_apaga_o_sqlite_do_servidor(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString('public_html/server/', $yml);
        $this->assertStringContainsString('/database/*.sqlite*', $yml);
        $this->assertStringContainsString('touch database/database.sqlite', $yml);
        $this->assertStringContainsString('php artisan migrate --force', $yml);
    }

    public function test_deploy_nao_apaga_o_env_do_servidor(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString('--exclude=.env', $yml);
        $this->assertStringContainsString('.env.backup', $yml);
        $this->assertStringContainsString('if [ ! -f .env ]; then', $yml);
        $this->assertStringContainsString('SCRIPT_AFTER_REQUIRED:', $yml);
        $this->assertStringNotContainsString('TARGET: "domains/missaonomeacao.com.br/public_html/"', $yml);
    }

    public function test_deploy_nao_apaga_as_capas_das_turmas(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString('--exclude=public/storage/', $yml);
        $this->assertStringContainsString('/public/storage/', $yml);
        $this->assertStringContainsString('--exclude=storage/app/public/', $yml);
        $this->assertStringContainsString('/storage/app/public/', $yml);

        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('/public/storage', $gitignore);

        $this->assertSame(public_path('storage'), config('filesystems.disks.public.root'));
    }

    public function test_css_do_consolidado_nao_deixa_bloco_cinza_no_rodape(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $css = $ref->getMethod('cssPdfConsolidado')->invoke($downloader, 'DejaVu Sans', '');

        $this->assertStringContainsString('@page{margin:34mm 16mm 18mm 16mm;}', $css);
        $this->assertStringContainsString('background:#ffffff', $css);
        $this->assertStringNotContainsString('#F5F5F5', $css);
        $this->assertStringContainsString('border-radius:9px', $css);
        $this->assertDoesNotMatchRegularExpression('/body\{[^}]*border-radius/', $css);
    }

    public function test_consolidado_nao_inclui_desempenho_nem_historico_de_metas(): void
    {
        $php = (string) file_get_contents((new ReflectionClass(CoachReportDownloader::class))->getFileName());
        $this->assertStringNotContainsString('<h2>Histórico de Metas</h2>', $php);
        $this->assertStringNotContainsString('Performance por Área', $php);
        $this->assertStringNotContainsString('Horas de estudo', $php);
        $this->assertStringNotContainsString('chartDesempenhoHorasEstudo', $php);
        $this->assertStringNotContainsString('chartDesempenhoPerformance', $php);
        $this->assertStringContainsString('paresMetricasDesempenho', $php);
        $this->assertStringContainsString('chart_horas_diarias', $php);
        $this->assertStringContainsString('chart_line_comparativo', $php);
        $this->assertStringContainsString('chart_questoes_dia', $php);
        $pdf = (string) file_get_contents((new ReflectionClass(RelatorioQuinzenalPdf::class))->getFileName());
        $this->assertStringContainsString('Revisões no período', $pdf);
        $this->assertStringContainsString('Ritmo de estudos', $pdf);
        $this->assertStringContainsString('Histórico completo', $pdf);
        $this->assertStringNotContainsString('Horas planejadas × horas (brutas) estudadas', $php);
        $this->assertStringNotContainsString("' (brutas)'", $php);
    }

    public function test_ritmo_de_estudos_vira_barras_agrupadas_com_todas_as_datas(): void
    {
        $downloader = new CoachReportDownloader('2', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $alinhar = $ref->getMethod('alinharSeriesAsDatas');
        $barras = $ref->getMethod('aplicarBarrasRitmoEstudos');
        $datas = $ref->getMethod('datasCompactasDoPeriodo');

        $fevereiro = $datas->invoke($downloader, new \DateTimeImmutable('2026-03-01'));
        $this->assertSame('16/02', $fevereiro[0]);
        $this->assertSame('28/02', $fevereiro[count($fevereiro) - 1]);
        $this->assertCount(13, $fevereiro);

        $agosto = $datas->invoke($downloader, new \DateTimeImmutable('2026-09-01'));
        $this->assertSame('16/08', $agosto[0]);
        $this->assertSame('31/08', $agosto[count($agosto) - 1]);
        $this->assertCount(16, $agosto);

        $fonte = [
            'type' => 'line',
            'data' => [
                'labels' => ['16/08', '18/08', '31/08'],
                'datasets' => [
                    ['label' => 'Horas brutas', 'data' => [0, 3.5, 2]],
                    ['label' => 'Horas líquidas', 'data' => [0, 2, 1]],
                ],
            ],
        ];
        $alinhado = $alinhar->invoke($downloader, $fonte, $agosto);
        $this->assertCount(16, $alinhado['data']['labels']);
        $this->assertSame(0, $alinhado['data']['datasets'][0]['data'][0]);
        $this->assertNull($alinhado['data']['datasets'][0]['data'][1]);
        $this->assertSame(3.5, $alinhado['data']['datasets'][0]['data'][2]);
        $this->assertSame(2, $alinhado['data']['datasets'][0]['data'][15]);

        $cfg = $barras->invoke($downloader, $fonte);
        $this->assertSame('bar', $cfg['type']);
        $this->assertFalse($cfg['options']['scales']['xAxes'][0]['stacked']);
        $this->assertFalse($cfg['options']['scales']['xAxes'][0]['ticks']['autoSkip']);
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, $cfg['data']['datasets'][0]['backgroundColor']);
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, $cfg['data']['datasets'][1]['backgroundColor']);
        $this->assertSame('__DATALABEL_HOURS_BAR__', $cfg['options']['plugins']['datalabels']['formatter']);
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, $cfg['options']['scales']['xAxes'][0]['ticks']['fontColor']);
        $this->assertSame('600', $cfg['options']['scales']['xAxes'][0]['ticks']['fontStyle']);
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, $cfg['options']['scales']['yAxes'][0]['ticks']['fontColor']);
        $this->assertSame(0, $cfg['options']['scales']['xAxes'][0]['ticks']['maxRotation']);
        $this->assertSame('top', $cfg['options']['legend']['position']);
        $this->assertSame('Horas', $cfg['options']['scales']['yAxes'][0]['scaleLabel']['labelString']);
        $this->assertSame('600', $cfg['options']['scales']['yAxes'][0]['scaleLabel']['fontStyle']);
        $this->assertSame('end', $cfg['options']['plugins']['datalabels']['align']);
        $this->assertSame('end', $cfg['options']['plugins']['datalabels']['anchor']);
        $this->assertSame(2, $cfg['data']['datasets'][0]['datalabels']['offset']);
        $this->assertSame(8, $cfg['data']['datasets'][1]['datalabels']['offset']);
        $this->assertSame('end', $cfg['data']['datasets'][0]['datalabels']['align']);
        $this->assertSame(7, $cfg['options']['plugins']['datalabels']['font']['size']);
        $this->assertNull($cfg['options']['plugins']['datalabels']['backgroundColor']);
        $this->assertSame(10.0, $cfg['options']['scales']['yAxes'][0]['ticks']['max']);
        $this->assertSame(2, $cfg['options']['scales']['yAxes'][0]['ticks']['stepSize']);
        $this->assertLessThan(0.70, $cfg['data']['datasets'][0]['categoryPercentage']);
        $this->assertGreaterThan($cfg['data']['datasets'][0]['categoryPercentage'], $cfg['data']['datasets'][0]['barPercentage']);
        $this->assertNotContains(RelatorioConsolidadoLayout::AZUL_CLARO, [
            $cfg['data']['datasets'][0]['backgroundColor'],
            $cfg['data']['datasets'][1]['backgroundColor'],
        ]);
    }

    public function test_acertos_e_erros_por_dia_vira_barras_navy_dourado(): void
    {
        $downloader = new CoachReportDownloader('2', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $barras = $ref->getMethod('aplicarBarrasAgrupadasInstitucionais');

        $fonte = [
            'type' => 'line',
            'data' => [
                'labels' => ['16/08', '17/08'],
                'datasets' => [
                    ['label' => 'Acertos', 'data' => [120, 80]],
                    ['label' => 'Erros', 'data' => [28, 12]],
                ],
            ],
        ];
        $cfg = $barras->invoke($downloader, $fonte, 'chart_questoes_dia');
        $this->assertSame('bar', $cfg['type']);
        $this->assertFalse($cfg['options']['scales']['xAxes'][0]['stacked']);
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, $cfg['data']['datasets'][0]['backgroundColor']);
        $this->assertSame(RelatorioConsolidadoLayout::DOURADO, $cfg['data']['datasets'][1]['backgroundColor']);
        $this->assertSame('__DATALABEL_COUNT_BAR__', $cfg['options']['plugins']['datalabels']['formatter']);
        $this->assertSame(RelatorioConsolidadoLayout::AZUL, $cfg['options']['scales']['xAxes'][0]['ticks']['fontColor']);
        $this->assertSame('600', $cfg['options']['scales']['yAxes'][0]['ticks']['fontStyle']);
        $this->assertSame('Questões', $cfg['options']['scales']['yAxes'][0]['scaleLabel']['labelString']);
        $this->assertSame('top', $cfg['options']['legend']['position']);
        $this->assertSame(0, $cfg['options']['scales']['xAxes'][0]['ticks']['maxRotation']);
        $this->assertSame(2, $cfg['data']['datasets'][0]['datalabels']['offset']);
        $this->assertSame(8, $cfg['data']['datasets'][1]['datalabels']['offset']);
        $this->assertSame('end', $cfg['data']['datasets'][0]['datalabels']['align']);
        $this->assertSame(7, $cfg['options']['plugins']['datalabels']['font']['size']);
    }

    public function test_grafico_de_horas_diarias_tem_rotulos_nos_vertices(): void
    {
        $php = (string) file_get_contents((new ReflectionClass(CoachReportDownloader::class))->getFileName());
        $this->assertStringContainsString('__DATALABEL_HOURS__', $php);
        $this->assertStringContainsString('__DATALABEL_HOURS_BAR__', $php);
        $this->assertStringContainsString('__DATALABEL_VALUE__', $php);
        $this->assertStringContainsString('__DATALABEL_COUNT_BAR__', $php);
        $this->assertStringContainsString('$isHours ? \'__DATALABEL_HOURS__\' : \'__DATALABEL_VALUE__\'', $php);

        $script = (string) file_get_contents(base_path('scripts/tutory-compose-pdf.mjs'));
        $this->assertStringContainsString('labelHoursOnChartVertices', $script);
        $this->assertStringContainsString('chart_horas_diarias', $script);
        $this->assertStringContainsString('chart_line_comparativo', $script);
        $this->assertStringContainsString('chart_questoes_dia', $script);
        $this->assertStringContainsString("chart.config.type = 'bar'", $script);
        $this->assertStringContainsString('return `${txt}h`', $script);
        $this->assertStringNotContainsString('stripPercentFromHoursCharts', $script);
        $this->assertStringContainsString('Horas planejadas × horas estudadas', $script);
        $this->assertStringContainsString('Horas estudadas = horas brutas registradas.', $script);
        $this->assertStringNotContainsString('Horas planejadas × horas (brutas) estudadas', $script);
        $this->assertStringContainsString('MISSÃO NOMEAÇÃO', $script);
        $this->assertStringNotContainsString('MISSÃO NOMEAÇÃO •', $script);
        $this->assertStringContainsString('headerPeriodoHtml', $script);
        $this->assertStringContainsString("replace(/\\s+[•/]\\s+/g, ' - ')", $script);
        $this->assertStringNotContainsString('border-radius:50%', $script);
        $this->assertStringContainsString('.kpi-num .kpi-v-a { font-size: 24pt; }', $script);
        $this->assertStringNotContainsString('font-size: 52pt', $script);
        $this->assertStringContainsString("top: '34mm'", $script);
        $this->assertStringContainsString("right: '16mm'", $script);
    }

    public function test_fallback_dompdf_gera_pdf_consolidado_a_partir_dos_htmls(): void
    {
        $pasta = sys_get_temp_dir().'/tutory-consolidado-'.uniqid('', true);
        mkdir($pasta, 0775, true);
        $destino = $pasta.'/relatorio_consolidado_20260818_2300_Giovanna_1.pdf';

        $htmls = [
            'desempenho' => '<html><body>
                <div class="main-header-card">
                    <div class="title-section"><h1>Seu desempenho</h1><p>Veja seu desempenho</p></div>
                    <div class="aluno-details"><h4>Giovanna</h4><p>Curso X</p></div>
                </div>
                <div class="metrics-grid">
                    <div class="metric-card"><p class="metric-label">Total de Horas</p><p class="metric-value">10h</p></div>
                    <div class="metric-card"><p class="metric-label">% de acertos</p><p class="metric-value">80%</p></div>
                </div>
                <script>var chartData = {horasEstudo:{labels:[],horas:[],mediaTopAlunos:{}},performance:{disciplinas:[],valores:[]}};</script>
            </body></html>',
            'aluno' => '<html><body>
                <h2 class="section-5">Histórico de Metas</h2>
                <p>Metas do período</p>
                <table id="tabela_estudos"><thead><tr><td>Disciplina</td></tr></thead><tbody></tbody></table>
                <h2 class="section-4">Revisões no Período</h2>
                <p>Revisões</p>
                <table id="tabela_revisoes"><thead><tr><td>Disciplina</td></tr></thead><tbody></tbody></table>
            </body></html>',
            'horas-liquidas' => '<html><body>
                <h2 class="section-2">Desempenho ao longo do Tempo</h2>
                <p>Comparativo</p>
                <canvas id="chart_line_comparativo"></canvas>
                <h2 class="section-4">Histórico</h2>
                <table id="tabela_horas_liquidas"><thead><tr><td>Disciplina</td></tr></thead><tbody></tbody></table>
            </body></html>',
            'questoes' => '<html><body>
                <h2 class="section-1">Breve Panorama</h2>
                <p>Questões</p>
                <div class="main-numbers"><p>questões</p><h3>10</h3></div>
                <div class="main-numbers"><p>acertos</p><h3>8</h3></div>
                <div class="main-numbers"><p>% acertos</p><h3>80%</h3></div>
                <h2 class="section-4">Performance por assunto</h2>
                <table id="tabela_questoes"><thead><tr><td>Disciplina</td><td>Assunto</td><td>Taxa</td></tr></thead>
                <tbody><tr><td>Português</td><td>Sintaxe</td><td>80%</td></tr></tbody></table>
            </body></html>',
            'progresso' => '<html><body>
                <h2 class="section-3">Motivação</h2>
                <p class="section-3-1">Horas por dia</p>
                <div class="insights-panel"><h6>Painel de Insights</h6><p>Média de 02:00</p></div>
            </body></html>',
        ];

        try {
            $downloader = new CoachReportDownloader('1', static function (): void {});
            $ref = new ReflectionClass($downloader);
            $ok = $ref->getMethod('gerarPdfConsolidadoDoHtml')->invoke($downloader, 'Giovanna', $htmls, $destino);

            $this->assertTrue($ok);
            $this->assertFileExists($destino);
            $this->assertGreaterThan(500, filesize($destino));
            $this->assertSame('%PDF', substr((string) file_get_contents($destino), 0, 4));
            $this->assertGreaterThanOrEqual(3, RelatorioPdfCapas::contarPaginas($destino));

            $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
            if ($pdftotext !== '') {
                $txt = (string) shell_exec(escapeshellcmd($pdftotext).' '.escapeshellarg($destino).' -');
                $this->assertStringContainsString('Giovanna', $txt);
                $this->assertStringContainsString('Curso X', $txt);
                $this->assertStringContainsString('DESEMPENHO', $txt);
                $this->assertStringContainsString('Missão Nomeação', $txt);
                $this->assertStringContainsStringIgnoringCase('Total de', $txt);
                $this->assertStringContainsString('10h', $txt);
                $this->assertStringContainsString('80%', $txt);
                $this->assertStringContainsString('02:00', $txt);
                $this->assertStringContainsStringIgnoringCase('Resumo da quinzena', $txt);
                $this->assertStringContainsString('Como foram os seus 15 dias', $txt);
                $this->assertStringContainsStringIgnoringCase('Performance por assunto', $txt);
                $this->assertStringContainsString('Sintaxe', $txt);
                $this->assertStringContainsString('Revisões no período', $txt);
                $this->assertStringContainsString('Nenhuma revisão registrada nesta quinzena', $txt);
                $this->assertStringNotContainsString('Histórico de Metas', $txt);
                $this->assertStringNotContainsString('Performance por Área', $txt);
                $this->assertStringNotContainsString('Horas de estudo', $txt);
                $this->assertStringNotContainsString('Breve Panorama', $txt);
                $posNome = mb_strpos($txt, 'Giovanna');
                $posResumo = mb_stripos($txt, 'Resumo da quinzena');
                $posAssuntos = mb_stripos($txt, 'Performance por assunto');
                $posRevisoes = mb_strpos($txt, 'Revisões no período');
                $this->assertNotFalse($posNome);
                $this->assertGreaterThan($posNome, $posResumo);
                $this->assertGreaterThan($posResumo, $posAssuntos);
                $this->assertGreaterThan($posAssuntos, $posRevisoes);
            }
        } finally {
            foreach (scandir($pasta) ?: [] as $arquivo) {
                if ($arquivo === '.' || $arquivo === '..') {
                    continue;
                }
                @unlink($pasta.'/'.$arquivo);
            }
            @rmdir($pasta);
        }
    }

    /**
     * @return array{model: string, nome: string}|null
     */
    private function chamar(string $metodo, string $arquivo): mixed
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);

        return $ref->getMethod($metodo)->invoke($downloader, $arquivo);
    }
}
