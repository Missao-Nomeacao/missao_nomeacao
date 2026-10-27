<?php

namespace App\Services\Tutory;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Throwable;

/**
 * PDF do relatório quinzenal no layout aprovado pela mentora:
 * capa (com nome, curso e período), páginas internas e última folha.
 *
 * Capa e miolo saem do Dompdf; a última folha é o PDF fixo
 * resources/relatorios/capa-final.pdf. As três partes são unidas por
 * RelatorioPdfCapas.
 */
final class RelatorioQuinzenalPdf
{
    public const AZUL = '#0B1F3F';

    public const AZUL_2 = '#132C55';

    public const AZUL_SUAVE = '#E7ECF4';

    public const DOURADO = '#F2B705';

    public const DOURADO_TEXTO = '#9A6B00';

    public const DOURADO_SUAVE = '#FDF3D3';

    public const TEXTO = '#0B1F3F';

    public const TEXTO_SEC = '#5A6479';

    public const LINHA = '#DDE3EC';

    public const FUNDO = '#F5F7FA';

    public const OK = '#1D7A4C';

    public const OK_SUAVE = '#E3F2EA';

    public const ATENCAO = '#B26A00';

    public const ATENCAO_SUAVE = '#FBEFD9';

    public const RUIM = '#B53636';

    /**
     * Largura útil do painel do gráfico: 794px da A4 − 2 × 14mm de margem − bordas e padding do painel.
     */
    private const LARGURA_GRAFICO = 644;

    /**
     * Gera o PDF completo em $destino.
     */
    public static function gerar(DadosRelatorioQuinzenal $dados, string $destino): void
    {
        $miolo = self::renderizar(self::htmlMiolo($dados));
        file_put_contents($destino, $miolo);

        $capa = null;
        try {
            $capa = $destino.'.capa.tmp.pdf';
            file_put_contents($capa, self::renderizar(self::htmlCapa($dados)));
        } catch (Throwable) {
            @unlink((string) $capa);
            $capa = null;
        }

        try {
            RelatorioPdfCapas::aplicar($destino, $capa);
        } finally {
            if ($capa !== null) {
                @unlink($capa);
            }
        }
    }

    public static function renderizar(string $html): string
    {
        $dompdf = new Dompdf(self::opcoes());
        PdfFontes::aplicarNoDompdf($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $bytes = (string) $dompdf->output();
        if (strlen($bytes) < 500) {
            throw new RuntimeException('Dompdf gerou um PDF vazio.');
        }

        return $bytes;
    }

    private static function opcoes(): Options
    {
        $opcoes = PdfFontes::opcoesDompdf('inter');
        $opcoes->set('defaultFont', 'Nunito');
        $opcoes->set('dpi', 96);

        return $opcoes;
    }

    private static function base(): string
    {
        return function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
    }

    /**
     * Famílias com um arquivo por peso: o Dompdf só distingue normal/negrito.
     */
    public static function fontes(): string
    {
        $faces = [
            ['MN Titulo', 'normal', 'montserrat/Montserrat-ExtraBold.ttf'],
            ['MN Titulo Bold', 'normal', 'montserrat/Montserrat-Bold.ttf'],
            ['MN Titulo Semi', 'normal', 'montserrat/Montserrat-SemiBold.ttf'],
            ['MN Titulo Light', 'normal', 'montserrat/Montserrat-Light.ttf'],
            ['Nunito', 'normal', 'nunito-sans/NunitoSans-Regular.ttf'],
            ['Nunito', 'bold', 'nunito-sans/NunitoSans-Bold.ttf'],
            ['Nunito Semi', 'normal', 'nunito-sans/NunitoSans-SemiBold.ttf'],
        ];
        $css = '';
        foreach ($faces as [$familia, $peso, $arquivo]) {
            if (! is_file(self::base().'/resources/fonts/'.$arquivo)) {
                continue;
            }
            $css .= "@font-face{font-family:'{$familia}';font-style:normal;font-weight:{$peso};src:url('resources/fonts/{$arquivo}') format('truetype');}";
        }

        return $css;
    }

    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }

    private static function imagemDataUri(string $relativo, string $mime): string
    {
        $path = self::base().'/resources/relatorios/'.$relativo;
        if (! is_file($path)) {
            return '';
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    // ------------------------------------------------------------------ capa

    public static function htmlCapa(DadosRelatorioQuinzenal $d): string
    {
        $fundo = self::imagemDataUri('capa-fundo.jpg', 'image/jpeg');
        $logo = self::imagemDataUri('logo-amarelo.png', 'image/png');
        $fontes = self::fontes();
        $aluno = self::e(trim($d->aluno));
        $chips = '';
        if ($d->cursoLimpo() !== '') {
            $chips .= '<span class="chip">Curso <b>'.self::e($d->cursoLimpo()).'</b></span>';
        }
        $chips .= '<span class="chip">Período <b>'.self::e($d->periodoPorExtenso()).'</b></span>';
        $az = self::AZUL;
        $ouro = self::DOURADO;
        $regua = self::reguaDourada();

        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><style>
{$fontes}
@page{margin:0;}
html,body{margin:0;padding:0;background:{$az};}
.pagina{position:relative;width:794px;height:1122px;overflow:hidden;background:{$az};}
.fundo{position:absolute;left:0;top:0;width:794px;height:1122px;}
.linha-a,.linha-b{position:absolute;top:0;width:2px;height:1122px;background:{$ouro};}
.linha-a{right:31px;}
.linha-b{right:24px;}
.logo{position:absolute;left:68px;top:83px;width:220px;}
.titulo{position:absolute;left:68px;top:440px;color:#ffffff;}
.kicker{font-family:'MN Titulo Light';font-size:47px;letter-spacing:2px;text-transform:uppercase;line-height:1.05;}
.main{font-family:'MN Titulo';font-size:71px;text-transform:uppercase;line-height:1.02;}
.regua{width:420px;height:3px;margin-top:18px;}
.aluno{position:absolute;left:68px;top:900px;color:#ffffff;}
.rotulo{font-family:'Nunito';font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#AFC0DC;}
.nome{font-family:'MN Titulo';font-size:40px;line-height:1.05;margin-top:4px;}
.chips{margin-top:12px;}
.chip{display:inline-block;border:1px solid #4A5F82;border-radius:999px;padding:4px 12px;font-family:'Nunito';font-size:13px;color:#E6EBF4;margin-right:8px;}
.chip b{font-family:'Nunito';font-weight:bold;color:{$ouro};}
</style></head><body><div class="pagina">
<img class="fundo" src="{$fundo}" alt="">
<div class="linha-a"></div><div class="linha-b"></div>
<img class="logo" src="{$logo}" alt="Missão Nomeação">
<div class="titulo"><div class="kicker">Relatório de</div><div class="main">Desempenho</div>
<img class="regua" src="{$regua}" alt=""></div>
<div class="aluno"><div class="rotulo">Aluno</div><div class="nome">{$aluno}</div><div class="chips">{$chips}</div></div>
</div></body></html>
HTML;
    }

    /**
     * Degradê dourado → azul da régua da capa (o Dompdf não desenha gradiente em CSS).
     */
    private static function reguaDourada(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }
        $w = 840;
        $h = 6;
        $img = imagecreatetruecolor($w, $h);
        [$r1, $g1, $b1] = [0xF2, 0xB7, 0x05];
        [$r2, $g2, $b2] = [0x06, 0x14, 0x30];
        for ($x = 0; $x < $w; $x++) {
            $t = $x / ($w - 1);
            $c = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($img, $x, 0, $x, $h, (int) $c);
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    // ----------------------------------------------------------------- miolo

    public static function htmlMiolo(DadosRelatorioQuinzenal $d): string
    {
        $secoes = self::secaoResumo($d)
            .self::secaoRitmo($d)
            .self::secaoQuestoes($d)
            .self::secaoAssuntos($d)
            .self::secaoRevisoes($d)
            .self::secaoHistorico($d);

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><style>'
            .self::fontes().self::css()
            .'</style></head><body>'.$secoes.'</body></html>';
    }

    public static function css(): string
    {
        $az = self::AZUL;
        $az2 = self::AZUL_2;
        $azs = self::AZUL_SUAVE;
        $ouro = self::DOURADO;
        $ourot = self::DOURADO_TEXTO;
        $ouros = self::DOURADO_SUAVE;
        $sec = self::TEXTO_SEC;
        $linha = self::LINHA;
        $fundo = self::FUNDO;
        $ok = self::OK;
        $oks = self::OK_SUAVE;
        $at = self::ATENCAO;
        $ats = self::ATENCAO_SUAVE;
        $ruim = self::RUIM;

        return <<<CSS
@page{margin:14mm 14mm 14mm 14mm;}
html{padding:0;background:#ffffff;}
body{margin:0;padding:0;background:#ffffff;font-family:'Nunito';font-size:15px;line-height:1.5;color:{$az};}
p,h2,h3{margin:0;padding:0;}
table{border-collapse:collapse;}
td{vertical-align:top;padding:0;}
.secao{margin:0 0 28px;}
.cab{page-break-after:avoid;margin:0 0 16px;}
.eyebrow{font-family:'MN Titulo Bold';font-size:11.5px;letter-spacing:1.8px;text-transform:uppercase;color:{$ourot};}
h2{font-family:'MN Titulo Bold';font-size:22px;line-height:1.3;margin-top:2px;color:{$az};}
.lead{color:{$sec};margin-top:4px;}
.panel{background:#ffffff;border:1px solid {$linha};border-radius:14px;padding:16px 20px;page-break-inside:avoid;}
.panel + .panel{margin-top:16px;}
.mt{margin-top:12px;}

.keep{page-break-inside:avoid;}
.grade{width:712px;margin-left:-12px;table-layout:fixed;border-collapse:separate;border-spacing:12px 0;}
.grade td.cel{vertical-align:top;}
.kpi{background:#ffffff;border:1px solid {$linha};border-radius:14px;padding:14px 16px;}
.kpi.hero{background:{$az};border-color:{$az};}
.kpi .k{font-family:'Nunito';font-weight:bold;font-size:12px;letter-spacing:0.7px;text-transform:uppercase;color:{$sec};}
.kpi.hero .k{color:#C9D3E6;}
.kpi .v{font-family:'MN Titulo';font-size:30px;line-height:1.15;margin-top:4px;color:{$az};}
.kpi.hero .v{color:{$ouro};}
.kpi .s{font-size:13px;color:{$sec};margin-top:4px;line-height:1.35;}
.kpi.hero .s{color:#C9D3E6;}

.acc{background:{$azs};border-radius:14px;padding:14px 20px;}
.acc td.c{padding-right:28px;}
.acc .k{font-family:'Nunito Semi';font-size:12px;color:{$sec};}
.acc .v{font-family:'MN Titulo Bold';font-size:20px;color:{$az};line-height:1.3;}
.acc .pg{padding-top:12px;}
.progresso{height:10px;background:#ffffff;border-radius:99px;margin-top:6px;}
.progresso div{height:10px;background:{$ouro};border-radius:99px;}

.ins{background:#ffffff;border:1px solid {$linha};border-radius:14px;padding:14px 16px;}
.grade td.kpi,.grade td.ins{vertical-align:top;}
.ins td.ic{width:50px;}
.dot{width:36px;height:36px;border-radius:10px;}
.dot td{width:36px;height:36px;padding:0;text-align:center;vertical-align:middle;font-family:'MN Titulo';font-size:18px;line-height:1;}
.dot.up{background:{$oks};color:{$ok};}
.dot.down{background:{$ats};color:{$at};}
.ins .k{font-family:'Nunito';font-weight:bold;font-size:12px;letter-spacing:0.7px;text-transform:uppercase;color:{$sec};}
.ins .v{font-family:'MN Titulo Bold';font-size:17px;margin-top:2px;color:{$az};line-height:1.3;}
.ins .s{font-size:13px;color:{$sec};line-height:1.35;}

.chart-head{width:100%;margin-bottom:6px;}
.chart-head h3{font-family:'MN Titulo Bold';font-size:15px;color:{$az};}
.legenda{text-align:right;vertical-align:middle;}
.leg{margin-left:auto;}
.leg td{vertical-align:middle;font-size:12.5px;color:{$sec};white-space:nowrap;}
.leg td.cor{width:12px;padding-right:6px;}
.leg td.nome{padding-right:16px;}
.leg td.nome.fim{padding-right:0;}
.sw{width:12px;height:12px;border-radius:3px;}
.sw.traco{height:3px;border-radius:2px;}

.qrow{width:100%;margin-top:10px;}
.qrow:first-child{margin-top:0;}
.qrow td{vertical-align:middle;}
.qrow td.d{width:52px;font-family:'Nunito';font-weight:bold;font-size:13.5px;}
.qrow td.p{width:64px;text-align:right;font-family:'Nunito';font-weight:bold;font-size:14px;}
.qtrack{background:{$fundo};border-radius:6px;height:22px;}
.qbar{height:22px;border-radius:6px;}
.qbar table{width:100%;height:22px;table-layout:fixed;}
.qbar td{height:22px;vertical-align:middle;font-family:'Nunito';font-weight:bold;font-size:11.5px;}
.qbar td.a{background:{$az};color:#ffffff;padding-left:8px;border-radius:6px 0 0 6px;}
.qbar td.e{background:{$ouro};color:{$az};text-align:center;border-radius:0 6px 6px 0;}
.qbar td.so{border-radius:6px;}

.topico{width:100%;border-bottom:1px solid {$linha};}
.topico.ultimo{border-bottom:0;}
.topico td{vertical-align:middle;padding:10px 0;}
.topico td.t{font-family:'Nunito Semi';}
.topico .dsc{font-family:'Nunito';font-size:12.5px;color:{$sec};}
.topico td.m{width:200px;padding-left:14px;padding-right:14px;}
.topico td.p{width:58px;text-align:right;font-family:'Nunito';font-weight:bold;}
.medidor{height:8px;background:{$fundo};border-radius:99px;}
.medidor div{height:8px;border-radius:99px;}
.ok{color:{$ok};} .atencao{color:{$at};} .ruim{color:{$ruim};}
.bg-ok{background:{$ok};} .bg-atencao{background:{$at};} .bg-ruim{background:{$ruim};}
.escala{font-size:12.5px;color:{$sec};margin-top:4px;}
.escala span{margin-right:16px;}
.ponto{display:inline-block;width:9px;height:9px;border-radius:5px;margin-right:5px;}

.vazio{border:1.5px dashed {$linha};border-radius:14px;padding:18px 20px;background:#ffffff;page-break-inside:avoid;}
.vazio td{vertical-align:middle;}
.vazio td.ic{width:54px;}
.vazio .icone{width:40px;height:40px;border-radius:20px;background:{$ouros};}
.vazio .icone td{width:40px;height:40px;padding:0;text-align:center;vertical-align:middle;color:{$ourot};font-family:'MN Titulo';font-size:18px;line-height:1;}
.vazio b{font-family:'Nunito';font-weight:bold;display:block;}
.vazio span{color:{$sec};font-size:14px;}

.disc{background:#ffffff;border:1px solid {$linha};border-radius:14px;margin-top:12px;page-break-inside:avoid;}
.disc.primeiro{margin-top:0;}
.disc-head{padding:14px 18px;}
.disc-head table{width:100%;}
.disc-head .n,.disc-head .h{font-family:'MN Titulo Bold';font-size:15px;color:{$az};}
.disc-head .h{text-align:right;white-space:nowrap;}
.share{height:6px;background:{$fundo};border-radius:99px;margin-top:6px;}
.share div{height:6px;background:{$az2};border-radius:99px;}
.itens{border-top:1px solid {$linha};}
.item{width:100%;font-size:14px;}
.item td{vertical-align:middle;padding:8px 0;}
.item td.a{padding-left:18px;}
.item td.md{width:130px;text-align:right;}
.item td.h{width:84px;text-align:right;padding-right:18px;font-family:'Nunito Semi';}
.item.sep td{border-top:1px solid {$fundo};}
.pill{display:inline-block;font-family:'Nunito';font-weight:bold;font-size:11.5px;border-radius:999px;padding:3px 10px;white-space:nowrap;}
.pill.estudo{background:{$azs};color:{$az2};}
.pill.exercicio{background:{$ouros};color:{$ourot};}
.pill.coach{background:{$oks};color:{$ok};}
.pill.video{background:#EEE8F7;color:#5B3E8A;}
CSS;
    }

    private static function cabecalho(string $eyebrow, string $titulo, string $lead = ''): string
    {
        $html = '<div class="cab"><p class="eyebrow">'.self::e($eyebrow).'</p><h2>'.self::e($titulo).'</h2>';
        if ($lead !== '') {
            $html .= '<p class="lead">'.self::e($lead).'</p>';
        }

        return $html.'</div>';
    }

    /**
     * Linha de cartões do mesmo tamanho, com 12px entre eles.
     *
     * @param  list<array{classe: string, html: string}>  $celulas
     */
    private static function grade(array $celulas): string
    {
        $w = round(100 / max(1, count($celulas)), 3);
        $html = '<table class="grade"><tr>';
        foreach ($celulas as $c) {
            $html .= '<td class="'.$c['classe'].'" style="width:'.$w.'%">'.$c['html'].'</td>';
        }

        return $html.'</tr></table>';
    }

    private static function classeFaixa(?float $pct): string
    {
        if ($pct === null) {
            return '';
        }

        return $pct >= 80 ? 'ok' : ($pct >= 70 ? 'atencao' : 'ruim');
    }

    // -------------------------------------------------- 1. resumo da quinzena

    private static function secaoResumo(DadosRelatorioQuinzenal $d): string
    {
        $q = $d->questoes();
        $plano = $d->planoCumprido();
        $media = $d->mediaDiaria();

        $kpis = [];
        if ($media !== null) {
            $kpis[] = self::kpi('Média por dia', $media, 'de estudo cronometrado', true);
        }
        if ($plano !== null) {
            $kpis[] = self::kpi(
                'Plano cumprido',
                $plano['percentual'].'%',
                DadosRelatorioQuinzenal::numeroBr($plano['estudado']).' h de '.DadosRelatorioQuinzenal::numeroBr($plano['planejado']).' h planejadas',
                $kpis === []
            );
        }
        if ($q['total'] !== null) {
            $kpis[] = self::kpi('Questões', (string) $q['total'], $q['acertos'] !== null ? $q['acertos'].' acertos' : '', $kpis === []);
        }
        if ($q['percentual'] !== null) {
            $kpis[] = self::kpi('Acertos', $q['percentual'], 'no período', $kpis === []);
        }

        $html = self::cabecalho('Resumo da quinzena', 'Como foram os seus '.$d->diasNoPeriodo().' dias');
        if ($kpis !== []) {
            $html .= self::grade($kpis);
        }
        $html .= self::acumulado($d);
        $html .= self::destaques($d);

        return '<div class="secao keep">'.$html.'</div>';
    }

    /**
     * @return array{classe: string, html: string}
     */
    private static function kpi(string $rotulo, string $valor, string $sub, bool $hero = false): array
    {
        return [
            'classe' => 'kpi'.($hero ? ' hero' : ''),
            'html' => '<div class="k">'.self::e($rotulo).'</div>'
                .'<div class="v">'.self::e($valor).'</div>'
                .($sub !== '' ? '<div class="s">'.self::e($sub).'</div>' : ''),
        ];
    }

    private static function acumulado(DadosRelatorioQuinzenal $d): string
    {
        $celulas = '';
        $progresso = '';
        foreach ($d->acumulado as $par) {
            $label = trim($par['label']);
            $valor = trim($par['value']);
            $pct = DadosRelatorioQuinzenal::percentual($valor);
            if ($pct !== null && str_contains(DadosRelatorioQuinzenal::normalizar($label), 'progresso')) {
                $w = max(0, min(100, $pct));
                $progresso = '<div class="pg"><div class="k">'.self::e($label).' · <b>'.self::e($valor).'</b></div>'
                    .'<div class="progresso"><div style="width:'.$w.'%"></div></div></div>';

                continue;
            }
            $celulas .= '<td class="c"><div class="k">'.self::e($label).'</div><div class="v">'.self::e($valor).'</div></td>';
        }
        if ($celulas === '' && $progresso === '') {
            return '';
        }

        return '<div class="acc mt">'.($celulas !== '' ? '<table><tr>'.$celulas.'</tr></table>' : '').$progresso.'</div>';
    }

    private static function destaques(DadosRelatorioQuinzenal $d): string
    {
        $cards = [];
        foreach ([
            [['mais estudada'], 'Matéria mais estudada', 'up', '↑', ''],
            [['menos estudada'], 'Matéria menos estudada', 'down', '↓', ' · merece atenção na próxima quinzena'],
        ] as [$chave, $rotulo, $classe, $seta, $extra]) {
            $materia = $d->insightContendo($chave);
            if ($materia === null || trim($materia) === '') {
                continue;
            }
            $seg = $d->horasDaDisciplina($materia);
            $sub = $seg !== null ? DadosRelatorioQuinzenal::horasCurtas($seg).' no período'.$extra : ltrim($extra, ' ·');
            $cards[] = [
                'classe' => 'ins',
                'html' => '<table style="width:100%"><tr><td class="ic"><table class="dot '.$classe.'"><tr><td>'.$seta.'</td></tr></table></td>'
                    .'<td><div class="k">'.self::e($rotulo).'</div><div class="v">'.self::e($d->nomeDisciplina($materia)).'</div>'
                    .($sub !== '' ? '<div class="s">'.self::e(mb_strtoupper(mb_substr($sub, 0, 1)).mb_substr($sub, 1)).'</div>' : '')
                    .'</td></tr></table>',
            ];
        }
        if ($cards === []) {
            return '';
        }

        return '<div class="mt">'.self::grade($cards).'</div>';
    }

    // ----------------------------------------------------- 2. ritmo de estudos

    private static function secaoRitmo(DadosRelatorioQuinzenal $d): string
    {
        $plano = GraficosRelatorioQuinzenal::planejadoEstudado($d->datas, $d->planejado, $d->estudado);
        $liquidas = GraficosRelatorioQuinzenal::brutasLiquidas($d->datas, $d->brutas, $d->liquidas);
        if ($plano === null && $liquidas === null) {
            return '';
        }

        $html = self::cabecalho(
            'Ritmo de estudos',
            'Planejado × estudado, dia a dia',
            'A barra mostra quanto você estudou. O traço dourado marca a meta do dia. Barras mais claras são dias que ficaram abaixo da meta.'
        );
        $primeiro = true;
        if ($plano !== null) {
            $primeiro = false;
            $html = '<div class="keep">'.$html.self::painelGrafico('Horas por dia', [
                ['Meta atingida', self::AZUL, false],
                ['Abaixo da meta', '#9FB0CC', false],
                ['Meta do dia', self::DOURADO, true],
            ], $plano).'</div>';
        }
        if ($liquidas !== null) {
            $painel = self::painelGrafico('Horas brutas × horas líquidas', [
                ['Brutas', self::AZUL, false],
                ['Líquidas', self::DOURADO, false],
            ], $liquidas);
            $html = $primeiro ? '<div class="keep">'.$html.$painel.'</div>' : $html.'<div class="mt"></div>'.$painel;
        }

        return '<div class="secao">'.$html.'</div>';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: bool}>  $legenda
     */
    private static function painelGrafico(string $titulo, array $legenda, string $img): string
    {
        return '<div class="panel"><table class="chart-head"><tr><td><h3>'.self::e($titulo).'</h3></td>'
            .'<td class="legenda">'.self::legenda($legenda).'</td></tr></table>'
            .'<img src="'.$img.'" alt="" width="'.self::LARGURA_GRAFICO.'" height="'.GraficosRelatorioQuinzenal::alturaPara(self::LARGURA_GRAFICO).'"></div>';
    }

    /**
     * Legenda em tabela: o Dompdf ignora margem lateral em elementos de linha.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $itens
     */
    private static function legenda(array $itens): string
    {
        $html = '<table class="leg"><tr>';
        $ultimo = count($itens) - 1;
        foreach ($itens as $i => [$nome, $cor, $traco]) {
            $html .= '<td class="cor"><div class="sw'.($traco ? ' traco' : '').'" style="background:'.$cor.'"></div></td>'
                .'<td class="nome'.($i === $ultimo ? ' fim' : '').'">'.self::e($nome).'</td>';
        }

        return $html.'</tr></table>';
    }

    // ------------------------------------------------------------ 3. questões

    private static function secaoQuestoes(DadosRelatorioQuinzenal $d): string
    {
        $dias = $d->diasComQuestoes();
        if ($dias === []) {
            return '';
        }
        $n = count($dias);
        $html = self::cabecalho(
            'Questões',
            'Acertos e erros por dia',
            'Você resolveu questões em '.$n.' dos '.$d->diasNoPeriodo().' dias da quinzena.'
        );

        $max = 1;
        foreach ($dias as $dia) {
            $max = max($max, $dia['acertos'] + $dia['erros']);
        }
        $linhas = '';
        foreach ($dias as $dia) {
            $t = $dia['acertos'] + $dia['erros'];
            $pct = $dia['acertos'] / $t * 100;
            $largura = round($t / $max * 100, 2);
            $wa = round($dia['acertos'] / $t * 100, 2);
            $we = round(100 - $wa, 2);
            $cel = '';
            if ($dia['acertos'] > 0) {
                $cel .= '<td class="a'.($dia['erros'] === 0 ? ' so' : '').'" style="width:'.$wa.'%">'.$dia['acertos'].'</td>';
            }
            if ($dia['erros'] > 0) {
                $cel .= '<td class="e'.($dia['acertos'] === 0 ? ' so' : '').'" style="width:'.$we.'%">'.$dia['erros'].'</td>';
            }
            $linhas .= '<table class="qrow"><tr><td class="d" style="width:52px">'.self::e($dia['data']).'</td>'
                .'<td><div class="qbar" style="width:'.$largura.'%"><table><tr>'.$cel.'</tr></table></div></td>'
                .'<td class="p '.self::classeFaixa($pct).'" style="width:64px">'.self::e(DadosRelatorioQuinzenal::numeroBr($pct, 1)).'%</td></tr></table>';
        }

        $legenda = self::legenda([['Acertos', self::AZUL, false], ['Erros', self::DOURADO, false]]);
        $html .= '<div class="panel"><table class="chart-head"><tr><td><h3>Dias com questões</h3></td>'
            .'<td class="legenda">'.$legenda.'</td></tr></table>'.$linhas.'</div>';

        return '<div class="secao keep">'.$html.'</div>';
    }

    // ------------------------------------------------ 4. performance por assunto

    private static function secaoAssuntos(DadosRelatorioQuinzenal $d): string
    {
        if ($d->assuntos === []) {
            return '';
        }
        $html = self::cabecalho('Performance por assunto', 'Onde você está acertando mais');
        $linhas = '';
        $ultimo = count($d->assuntos) - 1;
        foreach ($d->assuntos as $i => $a) {
            $pct = DadosRelatorioQuinzenal::percentual($a['taxa']);
            $faixa = self::classeFaixa($pct);
            $titulo = trim($a['assunto']) !== '' ? $a['assunto'] : $a['disciplina'];
            $sub = trim($a['assunto']) !== '' ? '<div class="dsc">'.self::e($d->nomeDisciplina($a['disciplina'])).'</div>' : '';
            $medidor = $pct !== null
                ? '<div class="medidor"><div class="bg-'.$faixa.'" style="width:'.max(0, min(100, $pct)).'%"></div></div>'
                : '';
            $linhas .= '<table class="topico'.($i === $ultimo ? ' ultimo' : '').'"><tr>'
                .'<td class="t">'.self::e($titulo).$sub.'</td>'
                .'<td class="m" style="width:200px">'.$medidor.'</td>'
                .'<td class="p '.$faixa.'" style="width:58px">'.self::e($a['taxa']).'</td></tr></table>';
        }
        $escala = '<div class="escala"><span><i class="ponto bg-ok"></i>80% ou mais</span>'
            .'<span><i class="ponto bg-atencao"></i>70% a 79%</span>'
            .'<span><i class="ponto bg-ruim"></i>abaixo de 70%</span></div>';
        $html .= '<div class="panel">'.$linhas.$escala.'</div>';

        return '<div class="secao keep">'.$html.'</div>';
    }

    // ----------------------------------------------------------- 5. revisões

    private static function secaoRevisoes(DadosRelatorioQuinzenal $d): string
    {
        $html = self::cabecalho('Revisões', 'Revisões no período');
        $rows = $d->revisoes['rows'];
        if ($rows === []) {
            $html .= '<div class="vazio"><table style="width:100%"><tr><td class="ic"><table class="icone"><tr><td>!</td></tr></table></td>'
                .'<td><b>Nenhuma revisão registrada nesta quinzena</b>'
                .'<span>Revisar é o que fixa o conteúdo. Reserve um horário fixo na semana para isso.</span></td></tr></table></div>';

            return '<div class="secao keep">'.$html.'</div>';
        }

        $linhas = '';
        $ultimo = count($rows) - 1;
        foreach ($rows as $i => $r) {
            $disc = (string) ($r[0] ?? '');
            $assunto = (string) ($r[1] ?? '');
            $qtd = count($r) >= 3 ? (string) end($r) : '';
            $linhas .= '<table class="topico'.($i === $ultimo ? ' ultimo' : '').'"><tr>'
                .'<td class="t">'.self::e($assunto !== '' ? $assunto : $disc)
                .($assunto !== '' ? '<div class="dsc">'.self::e($d->nomeDisciplina($disc)).'</div>' : '').'</td>'
                .'<td class="p" style="width:90px">'.self::e($qtd).'</td></tr></table>';
        }
        $html .= '<div class="panel">'.$linhas.'</div>';

        return '<div class="secao">'.$html.'</div>';
    }

    // ---------------------------------------------------------- 6. histórico

    private static function secaoHistorico(DadosRelatorioQuinzenal $d): string
    {
        $grupos = $d->historicoPorDisciplina();
        if ($grupos === []) {
            return '';
        }
        $html = self::cabecalho(
            'Histórico completo',
            'Para onde foram as suas horas',
            'Horas cronometradas por disciplina, com o detalhe de cada assunto.'
        );
        $max = max(1, $grupos[0]['segundos']);
        foreach ($grupos as $gi => $g) {
            $itens = '';
            foreach ($g['itens'] as $ii => $item) {
                $itens .= '<table class="item'.($ii > 0 ? ' sep' : '').'"><tr>'
                    .'<td class="a">'.self::e($item['assunto']).'</td>'
                    .'<td class="md" style="width:130px">'.($item['modalidade'] !== '' ? '<span class="pill '.self::classeModalidade($item['modalidade']).'">'.self::e($item['modalidade']).'</span>' : '').'</td>'
                    .'<td class="h" style="width:84px">'.self::e(self::horasSemZero($item['horas'])).'</td>'
                    .'</tr></table>';
            }
            $w = round($g['segundos'] / $max * 100, 2);
            $card = '<div class="disc'.($gi === 0 ? ' primeiro' : '').'"><div class="disc-head"><table><tr>'
                .'<td class="n">'.self::e($g['disciplina']).'</td>'
                .'<td class="h" style="width:90px">'.self::e(DadosRelatorioQuinzenal::horasCurtas($g['segundos'])).'</td></tr></table>'
                .'<div class="share"><div style="width:'.$w.'%"></div></div></div>'
                .'<div class="itens">'.$itens.'</div></div>';
            $html = $gi === 0 ? '<div class="keep">'.$html.$card.'</div>' : $html.$card;
        }

        return '<div class="secao">'.$html.'</div>';
    }

    /**
     * "07:51:35" → "7:51:35"; "00:45:07" → "0:45:07".
     */
    private static function horasSemZero(string $horas): string
    {
        return preg_replace('/^0(\d:)/', '$1', trim($horas)) ?? $horas;
    }

    private static function classeModalidade(string $modalidade): string
    {
        $m = DadosRelatorioQuinzenal::normalizar($modalidade);

        return match (true) {
            str_contains($m, 'coach') => 'coach',
            str_contains($m, 'video') => 'video',
            str_contains($m, 'exerc') || str_contains($m, 'quest') => 'exercicio',
            default => 'estudo',
        };
    }
}
