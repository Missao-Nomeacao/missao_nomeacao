<?php

namespace App\Services\Tutory;

use Dompdf\Dompdf;
use Throwable;

/**
 * Identidade visual e componentes do relatório quinzenal.
 *
 * Os dados da Tutory entram intocados; só o casco visual é fixo.
 * A paginação é consequência do conteúdo (seções modulares).
 */
final class RelatorioConsolidadoLayout
{
    public const AZUL = '#001D3D';

    public const AZUL_CLARO = '#3D6B99';

    public const DOURADO = '#BF8F00';

    public const VERMELHO = '#B42318';

    public const VERDE = '#1F7A3A';

    public const TEXTO = '#1F2937';

    public const TEXTO_SEC = '#4B5563';

    public const BORDA = '#E6E8EC';

    public const ZEBRA = '#F8F9FB';

    public const WHATSAPP_URL = 'https://wa.me/message/I53LOYY2D7CNI1';

    public const CTA_ANALISE = 'Quero entrar em contato';

    public const INTRO_HISTORICO = 'Confira o histórico completo de horas cronometradas no período.';

    public const TITULO_GRAFICO_PLANEJADAS = 'Horas planejadas × horas estudadas';

    public const LEGENDA_HORAS_ESTUDADAS = 'Horas estudadas = horas brutas registradas.';

    /**
     * @var list<string>
     */
    public const SECOES = [
        'Seu desempenho',
        'Ritmo de estudos',
        'Painel de Insights',
        'Desempenho em questões',
        'Performance por assunto',
        'Revisões no período',
        'Histórico completo',
    ];

    /**
     * @var array<int, string>
     */
    private const MESES = [
        1 => 'JANEIRO',
        2 => 'FEVEREIRO',
        3 => 'MARÇO',
        4 => 'ABRIL',
        5 => 'MAIO',
        6 => 'JUNHO',
        7 => 'JULHO',
        8 => 'AGOSTO',
        9 => 'SETEMBRO',
        10 => 'OUTUBRO',
        11 => 'NOVEMBRO',
        12 => 'DEZEMBRO',
    ];

    public static function rotuloPeriodo(string $periodo, ?\DateTimeInterface $ref = null): string
    {
        $ref ??= new \DateTimeImmutable('now');
        $mes = self::MESES[(int) $ref->format('n')] ?? mb_strtoupper($ref->format('F'));
        $n = $periodo === '2' ? '2' : '1';

        return $mes . ' - PERÍODO ' . $n;
    }

    public static function textoCabecalhoEsquerdo(): string
    {
        return 'MISSÃO NOMEAÇÃO';
    }

    public static function corPercentual(?float $pct): string
    {
        if ($pct === null) {
            return self::TEXTO_SEC;
        }
        if ($pct <= 65) {
            return self::VERMELHO;
        }
        if ($pct < 80) {
            return self::DOURADO;
        }

        return self::VERDE;
    }

    /**
     * @return list<string>
     */
    public static function paletaSeries(): array
    {
        return [self::AZUL, self::DOURADO, '#64748B', self::VERDE];
    }

    /**
     * Navy + dourado institucional — gráficos de barras agrupadas do consolidado.
     *
     * @return list<string>
     */
    public static function paletaRitmo(): array
    {
        return [self::AZUL, self::DOURADO];
    }

    public static function corSerieBarra(string $label, int $index): string
    {
        $h = self::normalizarRotulo($label);
        if (str_contains($h, 'erro') || str_contains($h, 'liquid') || str_contains($h, 'estudad')) {
            return self::DOURADO;
        }
        if (str_contains($h, 'acerto') || str_contains($h, 'bruta') || str_contains($h, 'planejad')) {
            return self::AZUL;
        }
        $paleta = self::paletaRitmo();

        return $paleta[$index % count($paleta)];
    }

    public static function secao(string $titulo, string $intro, string $body, string $classe = ''): string
    {
        if (trim($body) === '') {
            return '';
        }

        $cls = trim('mn-sec ' . $classe);
        $html = '<section class="' . $cls . '">';
        $html .= '<div class="mn-sec-head">';
        $html .= '<h2 class="mn-sec-title">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h2>';
        if (trim($intro) !== '') {
            $html .= '<p class="mn-sec-intro">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= '</div>';
        $html .= '<div class="mn-sec-body">' . $body . '</div>';
        $html .= '</section>';

        return $html;
    }

    public static function grafico(string $subtitulo, string $imgHtml, string $legenda = ''): string
    {
        if (trim($imgHtml) === '') {
            return '';
        }

        $html = '<div class="mn-chart">';
        if (trim($subtitulo) !== '') {
            $html .= '<p class="mn-chart-title">' . htmlspecialchars($subtitulo, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (trim($legenda) !== '') {
            $html .= '<p class="mn-chart-note">' . htmlspecialchars($legenda, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= $imgHtml;
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  list<array{label: string, value: string, destaque?: bool}>  $items
     */
    public static function cards(array $items, int $maxCols = 4): string
    {
        $items = array_values(array_filter(
            $items,
            static fn(array $i): bool => trim((string) ($i['label'] ?? '') . ($i['value'] ?? '')) !== ''
        ));
        if ($items === []) {
            return '';
        }

        $n = count($items);
        $maxCols = max(1, $maxCols);
        $cols = $n <= $maxCols ? max($n, 1) : $maxCols;
        $linhas = array_chunk($items, $cols);
        $html = '<table class="mn-kpis"><tbody>';
        foreach ($linhas as $linha) {
            $html .= '<tr>';
            $span = $cols - count($linha);
            foreach ($linha as $i => $item) {
                $colspan = ($i === count($linha) - 1 && $span > 0) ? ' colspan="' . ($span + 1) . '"' : '';
                $html .= self::htmlCardItem(
                    (string) $item['label'],
                    (string) $item['value'],
                    $colspan,
                    $cols
                );
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private static function htmlCardItem(string $label, string $valor, string $colspan, int $cols): string
    {
        $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $valorHtml = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
        if (self::eValorNumerico($valor)) {
            $compact = $cols >= 4;
            $valorCls = 'kpi-value ' . self::classeTamanhoValor($valor, $cols);

            return '<td class="kpi kpi-num' . ($compact ? ' kpi-compact' : '') . '"' . $colspan . '>'
                . '<div class="kpi-label">' . $labelHtml . '</div>'
                . '<div class="' . $valorCls . '">' . $valorHtml . '</div>'
                . '</td>';
        }

        return '<td class="kpi kpi-text"' . $colspan . '>'
            . '<div class="kpi-stack">'
            . '<div class="kpi-label">' . $labelHtml . '</div>'
            . '<div class="kpi-value kpi-long">' . $valorHtml . '</div>'
            . '</div>'
            . '</td>';
    }

    public static function eValorNumerico(string $valor): bool
    {
        $v = trim($valor);

        return $v !== '' && (bool) preg_match(
            '/^(?:\d{1,4}:\d{2}(?::\d{2})?|\d{1,3}(?:[.,]\d+)?\s*%|\d{1,6}(?:[.,]\d+)?\s*h(?:oras?)?|\d{1,6})$/iu',
            $v
        );
    }

    public static function classeTamanhoValor(string $valor, int $cols = 3): string
    {
        $len = mb_strlen(trim($valor));

        return match (true) {
            $len <= 3 => 'kpi-v-a',
            $len <= 4 => 'kpi-v-b',
            $len <= 5 => 'kpi-v-c',
            $len <= 6 => 'kpi-v-d',
            default => 'kpi-v-e',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array{numeric?: list<int>, percent_col?: int|null, class?: string}  $opts
     */
    public static function tabela(array $headers, array $rows, array $opts = []): string
    {
        if ($rows === [] && $headers === []) {
            return '';
        }

        $numeric = array_fill_keys($opts['numeric'] ?? [], true);
        $percentCol = $opts['percent_col'] ?? null;
        $class = $opts['class'] ?? 'mn-table';
        $colCount = max(count($headers), 1);
        foreach ($rows as $r) {
            $colCount = max($colCount, count($r));
        }

        $papeis = [];
        for ($i = 0; $i < $colCount; $i++) {
            $papeis[] = self::papelColuna((string) ($headers[$i] ?? ''), isset($numeric[$i]), $i === $percentCol);
        }
        $larguras = self::largurasColunas($papeis);

        $html = '<table class="' . $class . '"><colgroup>';
        foreach ($papeis as $i => $papel) {
            $html .= '<col class="mn-c-' . $papel . '" width="' . $larguras[$i] . '%" style="width:' . $larguras[$i] . '%">';
        }
        $html .= '</colgroup><thead><tr>';
        foreach ($headers as $i => $h) {
            $cls = self::classeColuna($papeis[$i] ?? 'texto');
            $attr = $cls !== '' ? ' class="' . $cls . '"' : '';
            $html .= '<th' . $attr . ' style="width:' . $larguras[$i] . '%">' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r => $cols) {
            $zebra = $r % 2 === 1 ? ' class="z"' : '';
            $html .= '<tr' . $zebra . '>';
            foreach ($cols as $i => $cell) {
                if ($i === $percentCol) {
                    $html .= self::celulaPercentual($cell);

                    continue;
                }
                $cls = self::classeColuna($papeis[$i] ?? 'texto');
                $attr = $cls !== '' ? ' class="' . $cls . '"' : '';
                $html .= '<td' . $attr . '>' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Painel de Insights: cada métrica vira um bloco RÓTULO + VALOR.
     * Os valores da Tutory entram intocados; só a forma visual muda.
     *
     * @param  list<string>  $paragrafos
     */
    public static function insights(array $paragrafos): string
    {
        $items = [];
        foreach ($paragrafos as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', (string) $p) ?? '');
            if ($p === '' || preg_match('/painel de insights/iu', $p)) {
                continue;
            }
            foreach (self::extrairParesInsight($p) as $par) {
                $items[] = ['label' => $par[0], 'value' => $par[1]];
            }
        }

        return self::cards($items, 3);
    }

    /**
     * Pares RÓTULO + VALOR do Painel de Insights, sem montar HTML.
     *
     * @param  list<string>  $paragrafos
     * @return list<array{label: string, value: string}>
     */
    public static function paresInsight(array $paragrafos): array
    {
        $items = [];
        foreach ($paragrafos as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', (string) $p) ?? '');
            if ($p === '' || preg_match('/painel de insights/iu', $p)) {
                continue;
            }
            foreach (self::extrairParesInsight($p) as $par) {
                $items[] = ['label' => $par[0], 'value' => $par[1]];
            }
        }

        return $items;
    }

    public static function alunoNome(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return '';
        }

        $maiusculo = mb_strtoupper($nome, 'UTF-8');

        return '<p class="mn-aluno-nome">' . htmlspecialchars($maiusculo, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    public static function alunoBloco(string $nome, string $curso): string
    {
        return self::alunoNome($nome)
            . ($curso !== '' ? '<p class="mn-aluno-curso">' . htmlspecialchars($curso, ENT_QUOTES, 'UTF-8') . '</p>' : '');
    }

    public static function css(string $fontCss, string $pieCss = '', string $fontFace = ''): string
    {
        $azul = self::AZUL;
        $ouro = self::DOURADO;
        $texto = self::TEXTO;
        $sec = self::TEXTO_SEC;
        $zebra = self::ZEBRA;

        return <<<CSS
{$fontFace}
@page{margin:34mm 16mm 18mm 16mm;}
html{font-family: {$fontCss}; font-size:10pt; color:{$texto}; padding:0; background:#ffffff;}
body{font-family: {$fontCss}; font-size:10pt; color:{$texto}; margin:0; padding:0; background:#ffffff; line-height:1.45;}
h1,h2,h3,h4,h5,h6,p,table,img,section,div{margin:0; padding:0;}
img{max-width:100%; height:auto;}
.mn-aluno-nome{font-size:26pt; font-weight:700; color:{$azul}; line-height:1.1; letter-spacing:-0.02em; text-transform:uppercase; margin:6px 0 18px; page-break-after:avoid;}
.mn-aluno-curso{font-size:10.5pt; font-weight:400; color:{$sec}; margin:0 0 8px;}
.mn-sec{margin:0 0 28px;}
.mn-sec-head{page-break-after:avoid; page-break-inside:avoid; margin:0;}
.mn-sec-title{font-size:16.5pt; font-weight:700; color:{$azul}; letter-spacing:-0.01em; padding:0 0 0 12px; border-left:3.5px solid {$ouro}; line-height:1.2;}
.mn-sec-intro{font-size:10.5pt; font-weight:400; color:{$sec}; margin:7px 0 0; padding-left:16px; page-break-after:avoid;}
.mn-sec-body{margin-top:14px;}
.mn-sec-keep{page-break-inside:avoid;}
.mn-sec-insights{page-break-inside:auto;}
.mn-sec-insights .mn-sec-head,.mn-sec-table .mn-sec-head{page-break-after:avoid;}
.mn-sec-insights .mn-kpis{border-spacing:14px 14px;}
.mn-kpis{width:100%; border-collapse:separate; border-spacing:14px 0; table-layout:fixed; margin:0 0 8px;}
.mn-kpis td.kpi{background:#ffffff; border:1.5pt solid {$ouro}; border-radius:9px; padding:18px 18px; vertical-align:middle; page-break-inside:avoid;}
.mn-kpis td.kpi.kpi-num{vertical-align:top;}
.kpi-inner{width:100%; border-collapse:collapse; border-spacing:0; table-layout:fixed; margin:0; padding:0; border:0;}
.kpi-inner col.kpi-c-label{width:40%;}
.kpi-inner col.kpi-c-value{width:60%;}
.kpi-inner td{border:0; margin:0; padding:0; vertical-align:middle; background:transparent;}
.kpi-row{display:table; width:100%; table-layout:fixed; margin:0; padding:0; border:0;}
.kpi-stack{display:block; width:100%; margin:0; padding:0;}
.kpi-label{font-size:9.5pt; font-weight:600; color:{$sec}; letter-spacing:0.04em; text-transform:uppercase; margin:0; line-height:1.15; text-align:left;}
.kpi-value{font-size:21pt; font-weight:700; color:{$azul}; line-height:0.95; text-align:left; word-wrap:break-word; overflow-wrap:break-word; word-break:normal; padding:0; white-space:nowrap; letter-spacing:-0.03em;}
.kpi-row .kpi-label,.kpi-row .kpi-value{display:table-cell; vertical-align:middle;}
.kpi-num .kpi-label{display:block; width:auto; max-width:92%; margin:0 0 2px; padding:0; font-size:8pt; line-height:1.12; font-weight:600;}
.kpi-num .kpi-value{display:block; width:auto; max-width:100%; margin:4px 0 0; padding:0; font-weight:700; text-align:left; white-space:nowrap; letter-spacing:-0.04em; line-height:0.92;}
.kpi-num .kpi-v-a{font-size:24pt;}
.kpi-num .kpi-v-b{font-size:22pt;}
.kpi-num .kpi-v-c{font-size:20pt;}
.kpi-num .kpi-v-d{font-size:18pt;}
.kpi-num .kpi-v-e{font-size:16pt;}
.kpi-compact .kpi-label{font-size:8pt; line-height:1.12;}
.kpi-compact .kpi-v-a{font-size:20pt;}
.kpi-compact .kpi-v-b{font-size:18pt;}
.kpi-compact .kpi-v-c{font-size:16.5pt;}
.kpi-compact .kpi-v-d{font-size:15pt;}
.kpi-compact .kpi-v-e{font-size:13.5pt;}
.kpi-text .kpi-label{margin:0 0 6px; line-height:1.20;}
.kpi-long{font-size:11.5pt; font-weight:700; line-height:1.20; text-align:left; padding:0; white-space:normal; letter-spacing:0;}
.mn-chart{margin:8px 0 16px; page-break-inside:avoid;}
.mn-chart-title{font-size:11pt; font-weight:600; color:{$azul}; margin:0 0 8px;}
.mn-chart-note{font-size:9.5pt; font-weight:400; color:{$sec}; margin:0 0 12px;}
.chart{width:100%; max-width:100%; margin:8px 0 0;}
{$pieCss}
.mn-table{width:100%; max-width:100%; border-collapse:collapse; font-size:9pt; table-layout:fixed; margin:0; border:1.25pt solid {$azul};}
.mn-table thead{display:table-header-group;}
.mn-table th,.mn-table td{height:auto; border:1.25pt solid {$azul};}
.mn-table th{background:{$azul}; color:#ffffff; font-weight:600; font-size:9pt; letter-spacing:0.03em; text-transform:uppercase; padding:9px 11px; text-align:left; vertical-align:middle; white-space:normal;}
.mn-table th.num{text-align:right;}
.mn-table th.mn-disc,.mn-table th.mn-assunto,.mn-table th.mn-mod,.mn-table th.mn-horas,.mn-table th.mn-qtd,.mn-table th.mn-pct{text-align:center; vertical-align:middle;}
.mn-table td.num{white-space:nowrap;}
.mn-table td.mn-horas{white-space:nowrap;}
.mn-table td{padding:9px 11px; vertical-align:middle; color:{$texto}; word-wrap:break-word; overflow-wrap:break-word; word-break:normal; font-size:9pt; font-weight:400; line-height:1.15; white-space:normal;}
.mn-table td.mn-assunto,.mn-table td.mn-disc,.mn-table td.mn-mod{white-space:normal; word-wrap:break-word; overflow-wrap:break-word;}
.mn-table td.mn-disc,.mn-table td.mn-mod,.mn-table td.mn-horas,.mn-table td.num,.mn-table td.mn-assunto{text-align:center; vertical-align:middle;}
.mn-table td.mn-pct{text-align:center;}
.mn-table tr.z td{background:{$zebra};}
.mn-table tr{page-break-inside:avoid; break-inside:avoid; height:auto;}
.mn-table thead{page-break-after:avoid;}
.mn-table tbody tr:first-child,.mn-table tbody tr:nth-child(2){page-break-before:avoid;}
.pct{font-weight:600; font-size:9.5pt;}
.bar-track{display:block; height:3px; background:#EEF0F3; margin:6px auto 0; width:72px; overflow:hidden;}
.bar-fill{display:block; height:3px;}
.mn-insight-item,.mn-insight-block{page-break-inside:avoid; margin:0 0 16px;}
.mn-insight-block{padding:10px 0 10px 12px; border-left:2px solid {$ouro};}
.mn-insight-label{font-size:8.5pt; font-weight:500; color:{$sec}; letter-spacing:0.03em; text-transform:uppercase; margin:0 0 6px;}
.mn-insight-value{font-size:10pt; font-weight:600; color:{$azul}; line-height:1.35;}
.empty{color:#6B7280; text-align:left; font-size:10pt; padding:8px 0;}
.keep{page-break-inside:avoid;}
CSS;
    }

    public static function aplicarCabecalhoRodape(Dompdf $dompdf, string $rotuloPeriodo): void
    {
        try {
            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $bold = $metrics->getFont('DejaVu Sans', 'bold')
                ?: $metrics->getFont('Inter', 'bold')
                ?: $metrics->getFont('Helvetica', 'bold');
            $regular = $metrics->getFont('DejaVu Sans', 'normal')
                ?: $metrics->getFont('Inter', 'normal')
                ?: $metrics->getFont('Helvetica', 'normal');
            if (! is_string($bold) || $bold === '' || ! is_string($regular) || $regular === '') {
                return;
            }

            $pageW = $canvas->get_width();
            $pageH = $canvas->get_height();
            $mm = 2.83465;
            $left = 16 * $mm;
            $right = $pageW - (16 * $mm);
            $azul = self::hexToRgb(self::AZUL);
            $ouro = self::hexToRgb(self::DOURADO);
            $cinza = self::hexToRgb(self::TEXTO_SEC);

            $marca = self::textoCabecalhoEsquerdo();
            $canvas->page_text($left, 26.0, $marca, $bold, 8.0, $azul);
            $rotuloW = $metrics->getTextWidth($rotuloPeriodo, $regular, 8.0);
            $canvas->page_text($right - $rotuloW, 26.0, $rotuloPeriodo, $regular, 8.0, $cinza);
            $canvas->page_line($left, 48.0, $right, 48.0, $ouro, 0.6);

            $pag = 'Página {PAGE_NUM} de {PAGE_COUNT}';
            $pagW = $metrics->getTextWidth('Página 00 de 00', $regular, 7.5);
            $canvas->page_text($right - $pagW, $pageH - 28.0, $pag, $regular, 7.5, $cinza);
        } catch (Throwable) {
            // Relatório segue válido sem chrome de página.
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function extrairParesInsight(string $texto): array
    {
        $pares = [];
        foreach (self::partirMetricasInsight($texto) as $parte) {
            $par = self::parInsight($parte);
            if ($par !== null) {
                $pares[] = $par;
            }
        }

        return $pares;
    }

    /**
     * @return list<string>
     */
    private static function partirMetricasInsight(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }
        if (preg_match('/[|•]/u', $texto)) {
            $partes = preg_split('/\s*[|•]\s*/u', $texto) ?: [];

            return array_values(array_filter(array_map('trim', $partes), static fn(string $p): bool => $p !== ''));
        }
        if (preg_match_all(
            '/(?:m[eé]dia(?:\s+di[aá]ria|\s+de)?|exerc[ií]cios(?:\s+realizados)?|acertos|taxa(?:\s+de)?\s+acertos)\s*[:\s]\s*(?:\d{1,2}:\d{2}(?::\d{2})?|\d+(?:[.,]\d+)?%?)/iu',
            $texto,
            $m
        ) && count($m[0]) > 1) {
            return array_map('trim', $m[0]);
        }

        return [$texto];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parInsight(string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        $narrativas = [
            ['/^(?:a\s+)?mat[eé]ria mais estudada\s+(?:foi|é|:)\s+(.+)$/iu', 'MATÉRIA MAIS ESTUDADA'],
            ['/^(?:a\s+)?mat[eé]ria menos estudada\s+(?:foi|é|:)\s+(.+)$/iu', 'MATÉRIA MENOS ESTUDADA'],
            ['/^(?:a\s+)?mat[eé]ria com maior solicita[cç][aã]o de tempo extra\s+(?:foi|é|:)\s+(.+)$/iu', 'MATÉRIA COM MAIOR SOLICITAÇÃO DE TEMPO EXTRA'],
            ['/^(?:.*?maior solicita[cç][aã]o de\s+)?tempo extra\s+(?:foi|é|:)\s+(.+)$/iu', 'MATÉRIA COM MAIOR SOLICITAÇÃO DE TEMPO EXTRA'],
            ['/^(.+?)\s+foi\s+a\s+mat[eé]ria\s+(?:que\s+voc[eê]\s+)?mais\s+estudou/iu', 'MATÉRIA MAIS ESTUDADA'],
            ['/^(.+?)\s+foi\s+a\s+mat[eé]ria\s+(?:que\s+voc[eê]\s+)?menos\s+estudou/iu', 'MATÉRIA MENOS ESTUDADA'],
        ];
        foreach ($narrativas as [$re, $rotulo]) {
            if (preg_match($re, $texto, $m)) {
                return [$rotulo, self::valorInsight($m[1])];
            }
        }

        $metricas = [
            ['/^m[eé]dia(?:\s+di[aá]ria|\s+de)?\s+(\d{1,2}:\d{2}(?::\d{2})?)\s*$/iu', 'MÉDIA DIÁRIA'],
            ['/^exerc[ií]cios(?:\s+realizados)?\s+(\d{1,6})\s*$/iu', 'EXERCÍCIOS REALIZADOS'],
            ['/^(\d{1,6})\s+exerc[ií]cios(?:\s+realizados)?\s*$/iu', 'EXERCÍCIOS REALIZADOS'],
            ['/^acertos\s+(\d{1,6})\s*$/iu', 'ACERTOS'],
            ['/^(\d{1,6})\s+acertos\s*$/iu', 'ACERTOS'],
            ['/^taxa(?:\s+de)?\s+acertos\s+(\d+(?:[.,]\d+)?%?)\s*$/iu', 'TAXA DE ACERTOS'],
            ['/^(\d+(?:[.,]\d+)?%)\s*$/u', 'TAXA DE ACERTOS'],
        ];
        foreach ($metricas as [$re, $rotulo]) {
            if (preg_match($re, $texto, $m)) {
                return [$rotulo, self::valorInsight($m[1])];
            }
        }

        if (preg_match('/^(.+?)\s+(\d{1,2}:\d{2}(?::\d{2})?|\d{1,3}(?:[.,]\d+)?%|\d{1,6})\s*$/u', $texto, $m)) {
            $rotulo = trim($m[1], " \t:-–—.");
            if ($rotulo !== '') {
                return [mb_strtoupper($rotulo, 'UTF-8'), self::valorInsight($m[2])];
            }
        }

        return null;
    }

    private static function valorInsight(string $valor): string
    {
        $valor = trim($valor);

        return rtrim($valor, " \t.");
    }

    private static function blocoInsight(string $label, string $valor): string
    {
        $html = '<div class="mn-insight-block">';
        if ($label !== '') {
            $html .= '<div class="mn-insight-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '<div class="mn-insight-value">' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @return array{0?: float, 1?: float, 2?: float}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private static function celulaPercentual(string $taxa): string
    {
        $n = self::parsePct($taxa);
        $cor = self::corPercentual($n);
        $html = '<td class="num mn-pct"><span class="pct" style="color:' . htmlspecialchars($cor, ENT_QUOTES, 'UTF-8') . ';">'
            . htmlspecialchars($taxa, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($n !== null) {
            $w = max(0, min(100, $n));
            $html .= '<span class="bar-track"><span class="bar-fill" style="width:' . $w . '%;background:'
                . htmlspecialchars($cor, ENT_QUOTES, 'UTF-8') . ';"></span></span>';
        }
        $html .= '</td>';

        return $html;
    }

    private static function parsePct(string $taxa): ?float
    {
        $t = str_replace(['%', ' '], '', trim($taxa));
        $t = str_replace(',', '.', $t);
        if (! is_numeric($t)) {
            return null;
        }

        return (float) $t;
    }

    /**
     * @return 'assunto'|'disciplina'|'modalidade'|'horas'|'pct'|'num'|'data'|'texto'
     */
    private static function papelColuna(string $header, bool $numeric, bool $percent): string
    {
        $h = self::normalizarRotulo($header);
        if ($percent || str_contains($h, 'taxa') || (str_contains($h, 'acerto') && (str_contains($h, 'percent') || str_contains($h, '%')))) {
            return 'pct';
        }
        if (str_contains($h, 'assunto')) {
            return 'assunto';
        }
        if (str_contains($h, 'modalidade')) {
            return 'modalidade';
        }
        if (str_contains($h, 'disciplina')) {
            return 'disciplina';
        }
        if (str_contains($h, 'hora')) {
            return 'horas';
        }
        if ($h === 'data' || $h === 'dia' || str_starts_with($h, 'data')) {
            return 'data';
        }
        if ($numeric || str_contains($h, 'revis')) {
            return 'num';
        }

        return 'texto';
    }

    private static function classeColuna(string $papel): string
    {
        return match ($papel) {
            'horas' => 'num mn-horas',
            'pct' => 'num mn-pct',
            'num', 'data' => 'num mn-qtd',
            'assunto' => 'mn-assunto',
            'disciplina' => 'mn-disc',
            'modalidade' => 'mn-mod',
            default => '',
        };
    }

    /**
     * Larguras em % da tabela: primeiro as colunas curtas, o resto para Assunto.
     *
     * @param  list<string>  $papeis
     * @return list<int>
     */
    private static function largurasColunas(array $papeis): array
    {
        $n = count($papeis);
        if ($n === 0) {
            return [];
        }

        $min = [
            'horas' => 13,
            'num' => 13,
            'pct' => 16,
            'data' => 9,
            'modalidade' => 16,
            'disciplina' => 20,
            'texto' => 12,
            'assunto' => 0,
        ];
        $w = array_fill(0, $n, 0);
        $assunto = [];
        $usadas = 0;
        foreach ($papeis as $i => $papel) {
            if ($papel === 'assunto') {
                $assunto[] = $i;

                continue;
            }
            $w[$i] = $min[$papel] ?? $min['texto'];
            $usadas += $w[$i];
        }

        $reservaAssunto = $assunto !== [] ? max(38, 100 - $usadas) : 0;
        if ($assunto !== [] && $usadas > 100 - $reservaAssunto) {
            $alvo = 100 - $reservaAssunto;
            $fator = $alvo / max(1, $usadas);
            $usadas = 0;
            foreach ($papeis as $i => $papel) {
                if ($papel === 'assunto') {
                    continue;
                }
                $w[$i] = max(8, (int) round($w[$i] * $fator));
                $usadas += $w[$i];
            }
        }

        $resto = 100 - $usadas;
        if ($assunto !== []) {
            $base = intdiv($resto, count($assunto));
            $sobra = $resto - ($base * count($assunto));
            foreach ($assunto as $k => $i) {
                $w[$i] = $base + ($k === 0 ? $sobra : 0);
            }
            $w = self::garantirAssuntoMaisLarga($w, $papeis);
        } else {
            $destino = self::indiceColunaTextoPrincipal($papeis);
            if ($destino !== null) {
                $w[$destino] += $resto;
            } elseif ($n > 0) {
                $w[0] += $resto;
            }
        }

        $soma = array_sum($w);
        if ($soma !== 100 && $n > 0) {
            $w[$n - 1] += 100 - $soma;
        }

        return $w;
    }

    /**
     * @param  list<int>  $w
     * @param  list<string>  $papeis
     * @return list<int>
     */
    private static function garantirAssuntoMaisLarga(array $w, array $papeis): array
    {
        $assunto = [];
        $maxOutra = 0;
        foreach ($papeis as $i => $papel) {
            if ($papel === 'assunto') {
                $assunto[] = $i;
            } else {
                $maxOutra = max($maxOutra, $w[$i]);
            }
        }
        if ($assunto === []) {
            return $w;
        }

        $piso = [
            'horas' => 12,
            'num' => 11,
            'pct' => 14,
            'data' => 8,
            'modalidade' => 13,
            'disciplina' => 16,
            'texto' => 10,
            'assunto' => 0,
        ];
        $alvo = $maxOutra + 6;
        foreach ($assunto as $idx) {
            if ($w[$idx] >= $alvo) {
                continue;
            }
            $need = $alvo - $w[$idx];
            foreach (['texto', 'modalidade', 'disciplina', 'data', 'num', 'pct'] as $role) {
                foreach ($papeis as $i => $papel) {
                    if ($papel !== $role || $need <= 0) {
                        continue;
                    }
                    $disp = $w[$i] - ($piso[$papel] ?? 8);
                    if ($disp <= 0) {
                        continue;
                    }
                    $take = min($disp, $need);
                    $w[$i] -= $take;
                    $w[$idx] += $take;
                    $need -= $take;
                }
            }
        }

        return $w;
    }

    /**
     * @param  list<string>  $papeis
     */
    private static function indiceColunaTextoPrincipal(array $papeis): ?int
    {
        foreach (['disciplina', 'texto', 'modalidade'] as $role) {
            foreach ($papeis as $i => $papel) {
                if ($papel === $role) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function normalizarRotulo(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $texto = is_string($ascii) && $ascii !== '' ? $ascii : $texto;

        return preg_replace('/[^a-z0-9]+/', '', $texto) ?? '';
    }
}
