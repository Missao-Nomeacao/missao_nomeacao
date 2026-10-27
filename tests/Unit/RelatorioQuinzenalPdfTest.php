<?php

namespace Tests\Unit;

use App\Services\Tutory\CoachReportDownloader;
use App\Services\Tutory\DadosRelatorioQuinzenal;
use App\Services\Tutory\GraficosRelatorioQuinzenal;
use App\Services\Tutory\PdfPreview;
use App\Services\Tutory\RelatorioPdfCapas;
use App\Services\Tutory\RelatorioQuinzenalPdf;
use ReflectionClass;
use Tests\TestCase;

class RelatorioQuinzenalPdfTest extends TestCase
{
    public function test_periodo_por_extenso_e_dias(): void
    {
        $d = new DadosRelatorioQuinzenal('Mychel', '', new \DateTimeImmutable('2026-09-16'), new \DateTimeImmutable('2026-09-30'));
        $this->assertSame('16 a 30 de setembro', $d->periodoPorExtenso());
        $this->assertSame(15, $d->diasNoPeriodo());

        $fev = new DadosRelatorioQuinzenal('X', '', new \DateTimeImmutable('2026-02-16'), new \DateTimeImmutable('2026-02-28'));
        $this->assertSame(13, $fev->diasNoPeriodo());
    }

    public function test_plano_cumprido_soma_as_series_do_grafico(): void
    {
        $d = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-03'),
            datas: ['01/09', '02/09', '03/09'],
            planejado: [3.0, 3.0, 4.0],
            estudado: [2.0, null, 3.0],
        );
        $plano = $d->planoCumprido();
        $this->assertNotNull($plano);
        $this->assertSame(50, $plano['percentual']);
        $this->assertSame(10.0, $plano['planejado']);
        $this->assertSame(5.0, $plano['estudado']);

        $semMeta = new DadosRelatorioQuinzenal('X', '', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-01'));
        $this->assertNull($semMeta->planoCumprido());
    }

    public function test_questoes_saem_do_panorama_e_caem_para_os_insights(): void
    {
        $panorama = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-15'),
            panorama: [
                ['label' => 'Questões realizadas', 'value' => '319'],
                ['label' => 'Acertos', 'value' => '254'],
                ['label' => 'Percentual de acertos', 'value' => '79,6%'],
            ],
        );
        $this->assertSame(['total' => 319, 'acertos' => 254, 'percentual' => '79,6%'], $panorama->questoes());

        $insights = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-15'),
            insights: [
                ['label' => 'EXERCÍCIOS REALIZADOS', 'value' => '20'],
                ['label' => 'ACERTOS', 'value' => '15'],
            ],
        );
        $this->assertSame(['total' => 20, 'acertos' => 15, 'percentual' => '75%'], $insights->questoes());
    }

    public function test_historico_agrupa_por_disciplina_sem_o_prefixo_do_curso(): void
    {
        $d = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-16'), new \DateTimeImmutable('2026-09-30'),
            historico: [
                ['disciplina' => 'PMSC - Português', 'assunto' => 'Crase', 'modalidade' => 'Estudo', 'horas' => '01:00:00'],
                ['disciplina' => 'PMSC - Direito Penal', 'assunto' => 'Furto', 'modalidade' => 'Exercício', 'horas' => '03:30:00'],
                ['disciplina' => 'PMSC - Português', 'assunto' => 'Vírgula', 'modalidade' => 'Estudo', 'horas' => '02:04:00'],
            ],
        );
        $grupos = $d->historicoPorDisciplina();
        $this->assertSame(['Direito Penal', 'Português'], array_column($grupos, 'disciplina'));
        $this->assertSame('3h04', DadosRelatorioQuinzenal::horasCurtas($grupos[1]['segundos']));
        $this->assertSame('Vírgula', $grupos[1]['itens'][0]['assunto']);
        $this->assertSame(11040, $d->horasDaDisciplina('PMSC - Português'));
        $this->assertSame('Português', $d->semPrefixoCurso('PMSC - Português'));
    }

    public function test_prefixo_so_sai_quando_todas_as_disciplinas_tem_o_mesmo(): void
    {
        $d = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-16'), new \DateTimeImmutable('2026-09-30'),
            historico: [
                ['disciplina' => 'PMSC - Português', 'assunto' => 'a', 'modalidade' => '', 'horas' => '01:00:00'],
                ['disciplina' => 'Raciocínio Lógico', 'assunto' => 'b', 'modalidade' => '', 'horas' => '01:00:00'],
            ],
        );
        $this->assertSame('PMSC - Português', $d->semPrefixoCurso('PMSC - Português'));
    }

    public function test_dias_com_questoes_ignora_dias_zerados(): void
    {
        $d = new DadosRelatorioQuinzenal(
            'X', '', new \DateTimeImmutable('2026-09-16'), new \DateTimeImmutable('2026-09-18'),
            datas: ['16/09', '17/09', '18/09'],
            acertosDia: [86.0, 0.0, null],
            errosDia: [18.0, 0.0, 4.0],
        );
        $this->assertSame([
            ['data' => '16/09', 'acertos' => 86, 'erros' => 18],
            ['data' => '18/09', 'acertos' => 0, 'erros' => 4],
        ], $d->diasComQuestoes());
    }

    public function test_miolo_segue_o_layout_aprovado(): void
    {
        $html = RelatorioQuinzenalPdf::htmlMiolo(PdfPreview::dadosDeExemplo());

        foreach ([
            'Resumo da quinzena', 'Como foram os seus 15 dias', 'Média por dia', 'Plano cumprido',
            'Ritmo de estudos', 'Planejado × estudado, dia a dia', 'Horas brutas × horas líquidas',
            'Acertos e erros por dia', 'Você resolveu questões em 5 dos 15 dias da quinzena.',
            'Onde você está acertando mais', 'Revisões no período', 'Nenhuma revisão registrada nesta quinzena',
            'Para onde foram as suas horas', 'merece atenção na próxima quinzena',
        ] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringContainsString('class="kpi hero"', $html);
        $this->assertStringContainsString('12h34', $html);
        $this->assertStringContainsString('7:51:35', $html);
        $this->assertStringContainsString('pill coach', $html);
        $this->assertStringContainsString('pill video', $html);
        $this->assertStringNotContainsString('PMSC - Português', $html);
        $this->assertStringNotContainsString('quickchart', $html);
        $this->assertStringContainsString('#0B1F3F', RelatorioQuinzenalPdf::css());
        $this->assertStringContainsString('#F2B705', RelatorioQuinzenalPdf::css());
    }

    public function test_faixas_de_acerto_seguem_80_e_70(): void
    {
        $html = RelatorioQuinzenalPdf::htmlMiolo(PdfPreview::dadosDeExemplo());
        $this->assertMatchesRegularExpression('/class="p ok"[^>]*>85,0%/', $html);
        $this->assertMatchesRegularExpression('/class="p atencao"[^>]*>75,8%/', $html);
    }

    public function test_capa_leva_nome_curso_e_periodo(): void
    {
        $html = RelatorioQuinzenalPdf::htmlCapa(PdfPreview::dadosDeExemplo());
        $this->assertStringContainsString('Mychel', $html);
        $this->assertStringContainsString('CFP · PMSC', $html);
        $this->assertStringContainsString('16 a 30 de setembro', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);
        $this->assertStringContainsString('data:image/jpeg;base64', $html);
    }

    public function test_graficos_saem_em_png_sem_servico_externo(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('php-gd ausente');
        }
        $png = GraficosRelatorioQuinzenal::planejadoEstudado(['16/09', '17/09'], [3.0, 3.0], [2.0, 3.5]);
        $this->assertNotNull($png);
        $this->assertStringStartsWith('data:image/png;base64,', $png);
        $this->assertNull(GraficosRelatorioQuinzenal::brutasLiquidas(['16/09'], [null], [0.0]));
    }

    public function test_preview_tem_capa_miolo_e_ultima_folha(): void
    {
        $tmp = sys_get_temp_dir().'/mn-quinzenal-'.uniqid('', true).'.pdf';
        file_put_contents($tmp, (new PdfPreview)->gerar());
        try {
            $this->assertSame('%PDF', substr((string) file_get_contents($tmp), 0, 4));
            $this->assertGreaterThanOrEqual(4, RelatorioPdfCapas::contarPaginas($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_rotulos_reais_da_tutory_sao_reconhecidos(): void
    {
        $html = '<canvas id="chart_questoes_dia"></canvas><script>
            var elQ = document.getElementById("chart_questoes_dia");
            new Chart(elQ, {type: "bar", data: {labels: ["04/10/2026", "05/10/2026"], datasets: [
                {label: "questões corretas", data: [19, 37]},
                {label: "questões erradas", data: [6, 11]}
            ]}});
        </script>';
        $downloader = new CoachReportDownloader('1', static function (): void {}, false, new \DateTimeImmutable('2026-10-20'));
        $ref = new ReflectionClass($downloader);
        $series = $ref->getMethod('seriesDoGrafico')->invoke(
            $downloader, $html, 'chart_questoes_dia', ['corret', 'acert', 'cert'], ['errad', 'erro', 'incorret'], false
        );
        $this->assertSame([19.0, 37.0], array_slice($series['a'], 3, 2));
        $this->assertSame([6.0, 11.0], array_slice($series['b'], 3, 2));

        $incorretas = str_replace(['questões corretas', 'questões erradas'], ['incorretas', 'corretas'], $html);
        $invertido = $ref->getMethod('seriesDoGrafico')->invoke(
            $downloader, $incorretas, 'chart_questoes_dia', ['corret', 'acert', 'cert'], ['errad', 'erro', 'incorret'], false
        );
        $this->assertSame([6.0, 11.0], array_slice($invertido['a'], 3, 2));
        $this->assertSame([19.0, 37.0], array_slice($invertido['b'], 3, 2));
    }

    public function test_textos_reais_da_tutory_no_resumo_e_na_capa(): void
    {
        $d = new DadosRelatorioQuinzenal(
            'Mychel', 'Curso: CFP - PMSC', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'),
            insights: [
                ['label' => 'SUA MÉDIA DE TEMPO POR DIA FOI DE', 'value' => '02:08'],
                ['label' => 'MATÉRIA MAIS ESTUDADA', 'value' => 'PORTUGUÊS'],
                ['label' => 'MATÉRIA MENOS ESTUDADA', 'value' => 'LEGISLAÇÃO DE TRÂNSITO'],
            ],
        );
        $this->assertSame('02:08', $d->mediaDiaria());
        $this->assertSame('CFP - PMSC', $d->cursoLimpo());
        $this->assertSame('Legislação de Trânsito', $d->nomeDisciplina('LEGISLAÇÃO DE TRÂNSITO'));
        $this->assertSame('Direito Penal Militar', $d->nomeDisciplina('DIREITO PENAL MILITAR'));
        $this->assertSame('Português', $d->nomeDisciplina('Português'));

        $miolo = RelatorioQuinzenalPdf::htmlMiolo($d);
        $this->assertStringContainsString('Média por dia', $miolo);
        $this->assertStringContainsString('Legislação de Trânsito', $miolo);
        $capa = RelatorioQuinzenalPdf::htmlCapa($d);
        $this->assertStringContainsString('Curso <b>CFP - PMSC</b>', $capa);
        $this->assertStringContainsString('1 a 15 de outubro', $capa);
    }

    public function test_legenda_dos_graficos_sai_em_celulas_separadas(): void
    {
        $html = RelatorioQuinzenalPdf::htmlMiolo(PdfPreview::dadosDeExemplo());
        $this->assertStringContainsString('<td class="nome">Acertos</td>', $html);
        $this->assertStringContainsString('<td class="nome fim">Erros</td>', $html);
        $this->assertStringNotContainsString('<span><i class="sw', $html);
    }

    public function test_dados_saem_dos_graficos_e_tabelas_da_tutory(): void
    {
        $graficoPlanejado = '<canvas id="chart_horas_diarias"></canvas><script>
            var elHoras = document.getElementById("chart_horas_diarias");
            new Chart(elHoras, {type: "bar", data: {labels: ["01/09", "02/09"], datasets: [
                {label: "Horas planejadas", data: [3, 4]},
                {label: "Horas estudadas", data: [2.5, 4.5]}
            ]}});
        </script>';
        $graficoQuestoes = '<canvas id="chart_questoes_dia"></canvas><script>
            var elQ = document.getElementById("chart_questoes_dia");
            new Chart(elQ, {type: "bar", data: {labels: ["01/09"], datasets: [
                {label: "Erros", data: [4]},
                {label: "Acertos", data: [16]}
            ]}});
        </script>';
        $htmls = [
            'desempenho' => '<div class="aluno-details"><h4>Mychel</h4><p>CFP · PMSC</p></div>
                <div class="metric-card"><p class="metric-label">Total de Horas</p><p class="metric-value">638:45</p></div>',
            'aluno' => '<table id="tabela_revisoes"><thead><tr><th>Disciplina</th><th>Assunto</th><th>Revisões</th></tr></thead>
                <tbody><tr><td>PMSC - Português</td><td>Crase</td><td>2</td></tr></tbody></table>',
            'horas-liquidas' => '<table id="tabela_horas_liquidas"><thead><tr><th>Disciplina</th><th>Assunto</th><th>Modalidade</th><th>Horas</th></tr></thead>
                <tbody><tr><td>PMSC - Português</td><td>Crase</td><td>Estudo</td><td>01:10:00</td></tr></tbody></table>',
            'questoes' => $graficoQuestoes.'<div class="main-numbers"><p>Questões</p><h3>20</h3></div>
                <table id="tabela_questoes"><thead><tr><th>Disciplina</th><th>Assunto</th><th>Taxa</th></tr></thead>
                <tbody><tr><td>PMSC - Português</td><td>Crase</td><td>80%</td></tr></tbody></table>',
            'progresso' => $graficoPlanejado.'<div class="insights-panel"><p>Média diária 02:39</p></div>',
        ];

        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $ref->getProperty('referenciaMes')->setValue($downloader, new \DateTimeImmutable('2026-09-05'));
        /** @var DadosRelatorioQuinzenal $d */
        $d = $ref->getMethod('montarDadosRelatorio')->invoke($downloader, $htmls);

        $this->assertSame('Mychel', $d->aluno);
        $this->assertSame('CFP · PMSC', $d->curso);
        $this->assertCount(15, $d->datas);
        $this->assertSame([3.0, 4.0], array_slice($d->planejado, 0, 2));
        $this->assertSame([2.5, 4.5], array_slice($d->estudado, 0, 2));
        $this->assertSame(16.0, $d->acertosDia[0]);
        $this->assertSame(4.0, $d->errosDia[0]);
        $this->assertSame('02:39', $d->insight('MÉDIA DIÁRIA'));
        $this->assertSame([['label' => 'Total de Horas', 'value' => '638:45']], $d->acumulado);
        $this->assertSame('80%', $d->assuntos[0]['taxa']);
        $this->assertSame('Crase', $d->revisoes['rows'][0][1]);
        $this->assertSame('01:10:00', $d->historico[0]['horas']);
    }
}
