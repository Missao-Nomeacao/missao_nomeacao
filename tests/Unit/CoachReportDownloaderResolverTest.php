<?php

namespace Tests\Unit;

use App\Services\Tutory\CoachReportDownloader;
use ReflectionClass;
use Tests\TestCase;

class CoachReportDownloaderResolverTest extends TestCase
{
    public function test_localiza_pelo_nome_quando_o_id_local_e_antigo(): void
    {
        $lista = [
            ['id' => '377712', 'nome' => 'Amanda Carvalho Vieira'],
            ['id' => '7711', 'nome' => 'Giovanna Campos'],
        ];

        $achado = $this->chamar('localizarAlunoNaLista', '27', 'Amanda Carvalho Vieira', $lista);

        $this->assertSame('377712', $achado['id']);
        $this->assertSame('Amanda Carvalho Vieira', $achado['nome']);
    }

    public function test_localiza_pelo_id_mesmo_com_nome_ligeiramente_diferente(): void
    {
        $lista = [
            ['id' => '377712', 'nome' => 'Amanda C. Vieira'],
        ];

        $achado = $this->chamar('localizarAlunoNaLista', '377712', 'Amanda Carvalho Vieira', $lista);

        $this->assertSame('377712', $achado['id']);
    }

    public function test_nao_acha_quando_nome_e_id_divergem(): void
    {
        $lista = [
            ['id' => '7711', 'nome' => 'Giovanna Campos'],
        ];

        $this->assertNull($this->chamar('localizarAlunoNaLista', '27', 'Amanda Carvalho Vieira', $lista));
    }

    public function test_mensagem_de_falha_traz_o_erro_da_tutory(): void
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);
        $ref->getProperty('ultimoErroPdf')->setValue(
            $downloader,
            'Falha ao gerar Desempenho na Tutory (id 27): aluno inválido'
        );

        $mensagem = $ref->getMethod('mensagemFalhaPdf')->invoke(
            $downloader,
            'Amanda Carvalho Vieira',
            '377712'
        );

        $this->assertStringContainsString('Amanda Carvalho Vieira', $mensagem);
        $this->assertStringContainsString('(id 377712)', $mensagem);
        $this->assertStringContainsString('aluno inválido', $mensagem);
    }

    /**
     * @param  list<array{nome: string, id: string}>  $lista
     * @return array{nome: string, id: string}|null
     */
    private function chamar(string $metodo, string $tutoryId, string $nome, array $lista): mixed
    {
        $downloader = new CoachReportDownloader('1', static function (): void {});
        $ref = new ReflectionClass($downloader);

        return $ref->getMethod($metodo)->invoke($downloader, $tutoryId, $nome, $lista);
    }
}
