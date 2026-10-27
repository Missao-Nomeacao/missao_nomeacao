<?php

namespace App\Services\Tutory;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Anexa a capa de abertura e a capa final ao PDF já gerado.
 *
 * O relatório é gerado antes, com a paginação interna intacta.
 * As capas entram por concatenação: não recebem cabeçalho, rodapé,
 * número de página nem margem, e não redesenham o conteúdo interno.
 */
final class RelatorioPdfCapas
{
    public const LARGURA_A4_MM = 210.0;

    public const ALTURA_A4_MM = 297.0;

    public static function caminhoCapa(): string
    {
        return self::diretorio().'/capa.pdf';
    }

    public static function caminhoCapaFinal(): string
    {
        return self::diretorio().'/capa-final.pdf';
    }

    public static function diretorio(): string
    {
        $base = function_exists('base_path')
            ? base_path()
            : dirname(__DIR__, 3);

        return $base.'/resources/relatorios';
    }

    /**
     * Transforma o PDF do relatório em: capa + relatório + capa final.
     *
     * $capaPath troca a capa fixa por outra (a capa com o nome do aluno).
     */
    public static function aplicar(string $pdfPath, ?string $capaPath = null): void
    {
        if (! is_file($pdfPath) || filesize($pdfPath) < 500) {
            throw new RuntimeException('PDF do relatório ausente ou vazio para anexar as capas.');
        }

        $capa = $capaPath !== null && is_file($capaPath) ? $capaPath : self::caminhoCapa();
        $capaFinal = self::caminhoCapaFinal();
        foreach ([$capa, $capaFinal] as $arquivo) {
            if (! is_file($arquivo) || filesize($arquivo) < 500) {
                throw new RuntimeException('Arquivo de capa ausente: '.$arquivo);
            }
        }

        $tmp = $pdfPath.'.capas.tmp.pdf';
        try {
            $pdf = self::novoDocumento();
            self::importarCapaEmA4($pdf, $capa);
            $internas = self::importarRelatorioIntacto($pdf, $pdfPath);
            if ($internas < 1) {
                throw new RuntimeException('O relatório não tem páginas para preservar.');
            }
            self::importarCapaEmA4($pdf, $capaFinal);
            $pdf->Output('F', $tmp);
            if (method_exists($pdf, 'cleanUp')) {
                $pdf->cleanUp();
            }
        } catch (Throwable $exc) {
            @unlink($tmp);
            throw $exc instanceof RuntimeException
                ? $exc
                : new RuntimeException('Falha ao anexar as capas do relatório: '.$exc->getMessage(), 0, $exc);
        }

        if (! is_file($tmp) || filesize($tmp) < 500) {
            @unlink($tmp);
            throw new RuntimeException('PDF final com capas ficou vazio.');
        }

        if (! @rename($tmp, $pdfPath)) {
            $ok = @copy($tmp, $pdfPath);
            @unlink($tmp);
            if (! $ok) {
                throw new RuntimeException('Não foi possível gravar o PDF com as capas.');
            }
        }
    }

    public static function contarPaginas(string $pdfPath): int
    {
        $pdf = self::novoDocumento();
        try {
            return $pdf->setSourceFile($pdfPath);
        } finally {
            if (method_exists($pdf, 'cleanUp')) {
                $pdf->cleanUp();
            }
        }
    }

    private static function novoDocumento(): Fpdi
    {
        $pdf = new class('P', 'mm', 'A4') extends Fpdi
        {
            public function Header(): void {}

            public function Footer(): void {}
        };
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        return $pdf;
    }

    private static function importarCapaEmA4(Fpdi $pdf, string $arquivo): void
    {
        $paginas = $pdf->setSourceFile($arquivo);
        if ($paginas < 1) {
            throw new RuntimeException('Capa sem páginas: '.$arquivo);
        }

        $tpl = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tpl);
        $srcW = (float) ($size['width'] ?? 0);
        $srcH = (float) ($size['height'] ?? 0);
        if ($srcW <= 0 || $srcH <= 0) {
            throw new RuntimeException('Capa com dimensão inválida: '.$arquivo);
        }

        $pdf->AddPage('P', [self::LARGURA_A4_MM, self::ALTURA_A4_MM]);

        $scale = min(self::LARGURA_A4_MM / $srcW, self::ALTURA_A4_MM / $srcH);
        $w = $srcW * $scale;
        $h = $srcH * $scale;
        $x = (self::LARGURA_A4_MM - $w) / 2;
        $y = (self::ALTURA_A4_MM - $h) / 2;
        $pdf->useTemplate($tpl, $x, $y, $w, $h);
    }

    private static function importarRelatorioIntacto(Fpdi $pdf, string $arquivo): int
    {
        $paginas = $pdf->setSourceFile($arquivo);
        for ($i = 1; $i <= $paginas; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $w = (float) ($size['width'] ?? 0);
            $h = (float) ($size['height'] ?? 0);
            if ($w <= 0 || $h <= 0) {
                throw new RuntimeException("Página interna {$i} com dimensão inválida.");
            }
            $orientacao = $w > $h ? 'L' : 'P';
            $pdf->AddPage($orientacao, [$w, $h]);
            $pdf->useTemplate($tpl, 0, 0, $w, $h);
        }

        return $paginas;
    }
}
