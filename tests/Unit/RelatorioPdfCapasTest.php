<?php

namespace Tests\Unit;

use App\Services\Tutory\PdfPreview;
use App\Services\Tutory\RelatorioPdfCapas;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class RelatorioPdfCapasTest extends TestCase
{
    public function test_arquivos_de_capa_ficam_no_repositorio(): void
    {
        $this->assertFileExists(RelatorioPdfCapas::caminhoCapa());
        $this->assertFileExists(RelatorioPdfCapas::caminhoCapaFinal());
        $this->assertGreaterThan(50_000, filesize(RelatorioPdfCapas::caminhoCapa()));
        $this->assertGreaterThan(10_000, filesize(RelatorioPdfCapas::caminhoCapaFinal()));
        $this->assertSame('%PDF', substr((string) file_get_contents(RelatorioPdfCapas::caminhoCapa()), 0, 4));
        $this->assertSame('%PDF', substr((string) file_get_contents(RelatorioPdfCapas::caminhoCapaFinal()), 0, 4));
        $this->assertSame(1, RelatorioPdfCapas::contarPaginas(RelatorioPdfCapas::caminhoCapa()));
        $this->assertSame(1, RelatorioPdfCapas::contarPaginas(RelatorioPdfCapas::caminhoCapaFinal()));
    }

    public function test_deploy_mantem_as_capas_no_servidor(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringNotContainsString('resources/relatorios', $yml);
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertStringNotContainsString('resources/relatorios', $gitignore);
        $this->assertStringNotContainsString('capa.pdf', $gitignore);
    }

    public function test_anexa_capa_e_capa_final_sem_alterar_paginacao_interna(): void
    {
        $origem = sys_get_temp_dir().'/mn-relatorio-'.uniqid('', true).'.pdf';
        $this->gravarPdfDeUmaPagina($origem, 'Pagina 1 de 1');

        try {
            $paginasAntes = RelatorioPdfCapas::contarPaginas($origem);
            $this->assertSame(1, $paginasAntes);

            RelatorioPdfCapas::aplicar($origem);

            $this->assertSame(3, RelatorioPdfCapas::contarPaginas($origem));
            $this->assertTrue($this->paginaEhA4($origem, 1));
            $this->assertTrue($this->paginaEhA4($origem, 3));

            $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
            if ($pdftotext === '') {
                $this->markTestSkipped('pdftotext ausente');
            }

            $capa = (string) shell_exec(escapeshellcmd($pdftotext).' -f 1 -l 1 '.escapeshellarg($origem).' -');
            $miolo = (string) shell_exec(escapeshellcmd($pdftotext).' -f 2 -l 2 '.escapeshellarg($origem).' -');
            $final = (string) shell_exec(escapeshellcmd($pdftotext).' -f 3 -l 3 '.escapeshellarg($origem).' -');

            $this->assertStringNotContainsString('Pagina 1 de 1', $capa);
            $this->assertStringNotContainsString('Pagina', $final);
            $this->assertStringContainsString('Pagina 1 de 1', $miolo);
        } finally {
            @unlink($origem);
        }
    }

    public function test_preview_abre_com_capa_e_fecha_com_capa_final(): void
    {
        $bytes = (new PdfPreview)->gerar('dejavu');
        $tmp = sys_get_temp_dir().'/mn-preview-capas-'.uniqid('', true).'.pdf';
        file_put_contents($tmp, $bytes);

        try {
            $paginas = RelatorioPdfCapas::contarPaginas($tmp);
            $this->assertGreaterThanOrEqual(3, $paginas);
            $this->assertTrue($this->paginaEhA4($tmp, 1));
            $this->assertTrue($this->paginaEhA4($tmp, $paginas));

            $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
            if ($pdftotext === '') {
                return;
            }

            $capa = (string) shell_exec(escapeshellcmd($pdftotext).' -f 1 -l 1 '.escapeshellarg($tmp).' -');
            $miolo = (string) shell_exec(
                escapeshellcmd($pdftotext).' -f 2 -l '.($paginas - 1).' '.escapeshellarg($tmp).' -'
            );
            $final = (string) shell_exec(
                escapeshellcmd($pdftotext).' -f '.$paginas.' -l '.$paginas.' '.escapeshellarg($tmp).' -'
            );

            $this->assertStringNotContainsString('Página', $capa);
            $this->assertStringNotContainsString('Página', $final);
            $this->assertStringContainsString('Página 1 de', $miolo);
            $this->assertStringContainsString('MYCHEL', $miolo);
            $this->assertStringContainsString('MISSÃO NOMEAÇÃO', $miolo);
            $this->assertStringNotContainsString('Página 1 de '.($paginas), $miolo);
        } finally {
            @unlink($tmp);
        }
    }

    private function gravarPdfDeUmaPagina(string $destino, string $texto): void
    {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->SetMargins(16, 20, 16);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(16, 40, $texto);
        $pdf->Output('F', $destino);
    }

    private function paginaEhA4(string $pdfPath, int $pagina): bool
    {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->setSourceFile($pdfPath);
        $tpl = $pdf->importPage($pagina);
        $size = $pdf->getTemplateSize($tpl);
        $w = (float) $size['width'];
        $h = (float) $size['height'];
        if (method_exists($pdf, 'cleanUp')) {
            $pdf->cleanUp();
        }

        return abs($w - RelatorioPdfCapas::LARGURA_A4_MM) < 1.0
            && abs($h - RelatorioPdfCapas::ALTURA_A4_MM) < 1.0;
    }
}
