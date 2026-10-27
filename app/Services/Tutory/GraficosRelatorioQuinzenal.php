<?php

namespace App\Services\Tutory;

/**
 * Gráficos do relatório quinzenal desenhados com GD, sem serviço externo.
 *
 * Cada método devolve um PNG em data URI, ou null quando não há dados
 * ou o GD não está disponível.
 */
final class GraficosRelatorioQuinzenal
{
    private const ESCALA = 2.5;

    private const LARGURA = 720;

    private const ALTURA = 180;

    private const MARGEM_ESQ = 34;

    private const MARGEM_DIR = 8;

    private const MARGEM_TOPO = 14;

    private const MARGEM_BASE = 30;

    private const AZUL = '#0B1F3F';

    private const AZUL_CLARO = '#9FB0CC';

    private const DOURADO = '#F2B705';

    private const GRADE = '#E6EAF1';

    private const TEXTO = '#5A6479';

    /**
     * Altura em px para exibir o gráfico numa largura, mantendo a proporção.
     */
    public static function alturaPara(int $largura): int
    {
        return (int) round($largura * self::ALTURA / self::LARGURA);
    }

    /**
     * Barras do estudado por dia, com o traço dourado da meta (planejado).
     * Dia que bateu a meta fica azul; abaixo dela, azul-claro.
     *
     * @param  list<string>  $datas
     * @param  list<?float>  $planejado
     * @param  list<?float>  $estudado
     */
    public static function planejadoEstudado(array $datas, array $planejado, array $estudado): ?string
    {
        if (! self::temDados($estudado) && ! self::temDados($planejado)) {
            return null;
        }
        $max = self::maximoEixo(array_merge($planejado, $estudado));
        $g = self::quadro($datas, $max);
        if ($g === null) {
            return null;
        }
        [$img, $y, $bw] = $g;

        foreach ($datas as $i => $_) {
            $cx = self::MARGEM_ESQ + $bw * $i + $bw / 2;
            $v = (float) ($estudado[$i] ?? 0);
            $meta = $planejado[$i] ?? null;
            $bateu = $meta === null || $v >= (float) $meta;
            if ($v > 0) {
                $w = $bw * 0.52;
                $yt = $y($v);
                self::retanguloArredondado($img, $cx - $w / 2, $yt, $cx + $w / 2, $y(0), 4, $bateu ? self::AZUL : self::AZUL_CLARO);
                if ($y(0) - $yt >= 20) {
                    self::textoCentro($img, self::numero($v), $cx, $yt + 15, 10.5, $bateu ? '#FFFFFF' : self::AZUL, true);
                }
            }
            if ($meta !== null && (float) $meta > 0) {
                $yp = $y((float) $meta);
                self::linhaGrossa($img, $cx - $bw * 0.36, $yp, $cx + $bw * 0.36, $yp, 3.5, self::DOURADO);
            }
        }

        return self::png($img);
    }

    /**
     * Barras lado a lado: horas brutas (azul) e líquidas (dourado).
     *
     * @param  list<string>  $datas
     * @param  list<?float>  $brutas
     * @param  list<?float>  $liquidas
     */
    public static function brutasLiquidas(array $datas, array $brutas, array $liquidas): ?string
    {
        if (! self::temDados($brutas) && ! self::temDados($liquidas)) {
            return null;
        }
        $max = self::maximoEixo(array_merge($brutas, $liquidas));
        $g = self::quadro($datas, $max);
        if ($g === null) {
            return null;
        }
        [$img, $y, $bw] = $g;

        foreach ($datas as $i => $_) {
            $cx = self::MARGEM_ESQ + $bw * $i + $bw / 2;
            $w = $bw * 0.3;
            foreach ([[(float) ($brutas[$i] ?? 0), self::AZUL, -1], [(float) ($liquidas[$i] ?? 0), self::DOURADO, 1]] as [$v, $cor, $lado]) {
                if ($v <= 0) {
                    continue;
                }
                $x = $lado < 0 ? $cx - $w - 1 : $cx + 1;
                self::retanguloArredondado($img, $x, $y($v), $x + $w, $y(0), 3, $cor);
            }
        }

        return self::png($img);
    }

    /**
     * @param  list<?float>  $serie
     */
    private static function temDados(array $serie): bool
    {
        foreach ($serie as $v) {
            if ((float) $v > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Topo do eixo: múltiplo de 2 acima do maior valor (mínimo 6 h).
     *
     * @param  list<?float>  $valores
     */
    private static function maximoEixo(array $valores): float
    {
        $max = 0.0;
        foreach ($valores as $v) {
            $max = max($max, (float) $v);
        }

        return max(6.0, ceil($max / 2) * 2);
    }

    /**
     * Cria a imagem com grade, rótulos do eixo Y (em horas) e dias no eixo X.
     *
     * @param  list<string>  $datas
     * @return array{0: \GdImage, 1: \Closure(float): float, 2: float}|null
     */
    private static function quadro(array $datas, float $max): ?array
    {
        if (! function_exists('imagecreatetruecolor') || $datas === []) {
            return null;
        }
        $s = self::ESCALA;
        $img = imagecreatetruecolor((int) (self::LARGURA * $s), (int) (self::ALTURA * $s));
        imageantialias($img, true);
        imagefill($img, 0, 0, self::cor($img, '#FFFFFF'));

        $ih = self::ALTURA - self::MARGEM_TOPO - self::MARGEM_BASE;
        $iw = self::LARGURA - self::MARGEM_ESQ - self::MARGEM_DIR;
        $bw = $iw / count($datas);
        $y = static fn (float $v): float => self::MARGEM_TOPO + $ih - ($v / $max) * $ih;

        $passo = $max / 3;
        for ($k = 0; $k <= 3; $k++) {
            $t = $passo * $k;
            $yy = $y($t);
            self::linhaGrossa($img, self::MARGEM_ESQ, $yy, self::LARGURA - self::MARGEM_DIR, $yy, 1, self::GRADE);
            self::textoDireita($img, self::numero($t).'h', self::MARGEM_ESQ - 8, $yy + 4, 11, self::TEXTO);
        }
        foreach ($datas as $i => $data) {
            $dia = substr($data, 0, 2);
            self::textoCentro($img, $dia, self::MARGEM_ESQ + $bw * $i + $bw / 2, self::ALTURA - 10, 11, self::TEXTO, true);
        }

        return [$img, $y, $bw];
    }

    private static function numero(float $v): string
    {
        return DadosRelatorioQuinzenal::numeroBr(round($v, 1), 1);
    }

    private static function cor(\GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return (int) imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private static function retanguloArredondado(\GdImage $img, float $x1, float $y1, float $x2, float $y2, float $r, string $hex): void
    {
        $s = self::ESCALA;
        [$x1, $y1, $x2, $y2, $r] = [(int) round($x1 * $s), (int) round($y1 * $s), (int) round($x2 * $s), (int) round($y2 * $s), (int) round($r * $s)];
        if ($y2 - $y1 < 1 || $x2 - $x1 < 1) {
            return;
        }
        $c = self::cor($img, $hex);
        $r = max(0, min($r, intdiv($x2 - $x1, 2), intdiv($y2 - $y1, 2)));
        if ($r === 0) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $c);

            return;
        }
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2, $c);
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y1 + $r, $c);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $c);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $c);
    }

    private static function linhaGrossa(\GdImage $img, float $x1, float $y1, float $x2, float $y2, float $espessura, string $hex): void
    {
        $s = self::ESCALA;
        $meia = max(0.5, $espessura * $s / 2);
        $c = self::cor($img, $hex);
        imagefilledrectangle(
            $img,
            (int) round($x1 * $s),
            (int) round($y1 * $s - $meia),
            (int) round($x2 * $s),
            (int) round($y1 * $s + $meia),
            $c
        );
        if ($espessura >= 3) {
            imagefilledellipse($img, (int) round($x1 * $s), (int) round($y1 * $s), (int) ($meia * 2), (int) ($meia * 2), $c);
            imagefilledellipse($img, (int) round($x2 * $s), (int) round($y2 * $s), (int) ($meia * 2), (int) ($meia * 2), $c);
        }
    }

    private static function fonte(bool $negrito): ?string
    {
        $arquivo = $negrito ? 'NunitoSans-Bold.ttf' : 'NunitoSans-SemiBold.ttf';
        $dir = function_exists('base_path') ? base_path('resources/fonts/nunito-sans') : dirname(__DIR__, 3).'/resources/fonts/nunito-sans';
        $path = $dir.'/'.$arquivo;

        return is_file($path) && function_exists('imagettftext') ? $path : null;
    }

    private static function textoCentro(\GdImage $img, string $texto, float $cx, float $base, float $tamanho, string $hex, bool $negrito = false): void
    {
        self::texto($img, $texto, $cx, $base, $tamanho, $hex, $negrito, 'centro');
    }

    private static function textoDireita(\GdImage $img, string $texto, float $x, float $base, float $tamanho, string $hex): void
    {
        self::texto($img, $texto, $x, $base, $tamanho, $hex, false, 'direita');
    }

    private static function texto(\GdImage $img, string $texto, float $x, float $base, float $tamanho, string $hex, bool $negrito, string $alinhamento): void
    {
        $s = self::ESCALA;
        $c = self::cor($img, $hex);
        $fonte = self::fonte($negrito);
        if ($fonte !== null) {
            $pt = $tamanho * $s * 0.75;
            $caixa = imagettfbbox($pt, 0, $fonte, $texto);
            $largura = $caixa !== false ? abs($caixa[2] - $caixa[0]) : 0;
            $px = $x * $s - ($alinhamento === 'centro' ? $largura / 2 : ($alinhamento === 'direita' ? $largura : 0));
            imagettftext($img, $pt, 0, (int) round($px), (int) round($base * $s), $c, $fonte, $texto);

            return;
        }
        $largura = imagefontwidth(3) * strlen($texto);
        $px = $x * $s - ($alinhamento === 'centro' ? $largura / 2 : ($alinhamento === 'direita' ? $largura : 0));
        imagestring($img, 3, (int) round($px), (int) round($base * $s - 12), $texto, $c);
    }

    private static function png(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
