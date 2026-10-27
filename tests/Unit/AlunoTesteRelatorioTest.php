<?php

namespace Tests\Unit;

use App\Console\Commands\BaixarRelatoriosTutoryCommand;
use App\Services\Tutory\CoachReportDownloader;
use ReflectionClass;
use Tests\TestCase;

class AlunoTesteRelatorioTest extends TestCase
{
    public function test_aluno_teste_e_mychel(): void
    {
        $ref = new ReflectionClass(CoachReportDownloader::class);

        $this->assertSame('Mychel', $ref->getConstant('ALUNO_TESTE'));
    }

    public function test_comando_descreve_mychel_no_modo_teste(): void
    {
        $comando = new BaixarRelatoriosTutoryCommand;
        $descricao = $comando->getDefinition()->getOption('teste')->getDescription();

        $this->assertStringContainsString('Mychel', $descricao);
        $this->assertStringNotContainsString('Giovanna', $descricao);
    }
}
