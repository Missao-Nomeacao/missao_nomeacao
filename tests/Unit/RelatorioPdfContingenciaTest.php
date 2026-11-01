<?php

namespace Tests\Unit;

use App\Mail\EmailRelatorioCoach;
use App\Models\Aluno;
use App\Services\Tutory\RelatorioPdfContingencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class RelatorioPdfContingenciaTest extends TestCase
{
    use RefreshDatabase;

    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdf = sys_get_temp_dir().'/relatorio_consolidado_contingencia_'.uniqid('', true).'.pdf';
        file_put_contents($this->pdf, '%PDF-1.4 contingencia');
    }

    protected function tearDown(): void
    {
        if (is_file($this->pdf)) {
            @unlink($this->pdf);
        }
        parent::tearDown();
    }

    public function test_recusa_envio_para_a_mentora(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '9001',
            'nome' => 'Nayara Oliveira',
            'email' => 'nayaradeoliveira.contato@gmail.com',
            'recebe_email' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A mentora não recebe o e-mail do relatório pelo cadastro de aluno.');

        (new RelatorioPdfContingencia)->validarEnvio($aluno);
    }

    public function test_recusa_envio_com_email_invalido(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '7711',
            'nome' => 'Giovanna',
            'email' => 'nao-e-email',
            'recebe_email' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('E-mail do aluno inválido');

        (new RelatorioPdfContingencia)->validarEnvio($aluno);
    }

    public function test_envia_email_com_cco_sem_alterar_faixas(): void
    {
        Mail::fake();
        $aluno = Aluno::create([
            'tutory_id' => '7711',
            'nome' => 'Giovanna',
            'email' => 'giovanna@example.com',
            'recebe_email' => false,
            'last_performance' => 'Excelente',
        ]);
        $aluno->forceFill(['created_at' => '2026-08-01 09:00:00'])->save();

        $logs = [];
        (new RelatorioPdfContingencia)->enviar(
            $aluno,
            [
                'year_month' => '2026-09',
                'period' => '1',
                'inicio' => '2026-09-01',
                'fim' => '2026-09-15',
            ],
            $this->pdf,
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            }
        );

        Mail::assertSent(EmailRelatorioCoach::class, function (EmailRelatorioCoach $mail) use ($aluno): bool {
            return $mail->hasTo($aluno->email)
                && $mail->hasBcc('nayara@missaonomeacao.com.br');
        });
        $this->assertSame('Excelente', $aluno->fresh()->last_performance);
        $this->assertTrue(collect($logs)->contains(
            fn (string $linha): bool => str_contains($linha, 'recebe_email está desmarcado')
        ));
        $this->assertTrue(collect($logs)->contains(
            fn (string $linha): bool => str_contains($linha, 'E-mail enviado para giovanna@example.com')
                && str_contains($linha, 'cco: nayara@missaonomeacao.com.br')
        ));
    }

    public function test_falha_de_smtp_vira_erro_explicito(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('SMTP recusou'));

        $aluno = Aluno::create([
            'tutory_id' => '7711',
            'nome' => 'Giovanna',
            'email' => 'giovanna@example.com',
            'recebe_email' => true,
        ]);
        $aluno->forceFill(['created_at' => '2026-08-01 09:00:00'])->save();

        try {
            (new RelatorioPdfContingencia)->enviar(
                $aluno,
                ['year_month' => '2026-09', 'period' => '1'],
                $this->pdf
            );
            $this->fail('Esperava RuntimeException no envio.');
        } catch (RuntimeException $exc) {
            $this->assertStringContainsString('Não foi possível enviar o e-mail', $exc->getMessage());
            $this->assertStringContainsString('SMTP recusou', $exc->getMessage());
        }
    }

    public function test_recusa_envio_sem_arquivo(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '7711',
            'nome' => 'Giovanna',
            'email' => 'giovanna@example.com',
            'recebe_email' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arquivo do PDF não encontrado para envio.');

        (new RelatorioPdfContingencia)->enviar(
            $aluno,
            ['year_month' => '2026-09', 'period' => '1'],
            $this->pdf.'.ausente'
        );
    }
}
