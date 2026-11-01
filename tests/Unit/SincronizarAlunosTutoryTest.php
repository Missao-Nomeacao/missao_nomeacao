<?php

namespace Tests\Unit;

use App\Models\Aluno;
use App\Services\Tutory\SincronizarAlunosTutory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SincronizarAlunosTutoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastra_aluno_ativo_com_recebe_email_true(): void
    {
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $resultado = $sync->sincronizarLista([
            ['id' => '1001', 'nome' => 'Maria Silva', 'email' => 'maria@example.com'],
        ]);

        $this->assertSame(1, $resultado['criados']);
        $aluno = Aluno::query()->first();
        $this->assertNotNull($aluno);
        $this->assertSame('1001', $aluno->tutory_id);
        $this->assertSame('Maria Silva', $aluno->nome);
        $this->assertSame('maria@example.com', $aluno->email);
        $this->assertTrue($aluno->recebe_email);
        $this->assertTrue($aluno->ativo);
        $this->assertTrue(collect($logs)->contains(fn (string $m) => str_contains($m, 'Cadastrado: Maria Silva')));
    }

    public function test_nome_da_tutory_prevalece_quando_diverge(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '1001',
            'nome' => 'Maria da Plataforma',
            'email' => 'maria@example.com',
            'recebe_email' => false,
        ]);
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $sync->sincronizarLista([
            ['id' => '1001', 'nome' => 'Maria Silva', 'email' => 'maria@example.com'],
        ]);

        $aluno->refresh();
        $this->assertSame('Maria Silva', $aluno->nome);
        $this->assertTrue($aluno->recebe_email);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'Nome divergente') && str_contains($m, 'Maria da Plataforma') && str_contains($m, 'Maria Silva')
        ));
    }

    public function test_email_da_tutory_prevalece_quando_aluno_ja_existe(): void
    {
        $aluno = Aluno::create([
            'nome' => 'João Lima',
            'email' => 'joao.plataforma@example.com',
            'recebe_email' => true,
        ]);
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $sync->sincronizarLista([
            ['id' => '2002', 'nome' => 'João Lima', 'email' => 'joao.tutory@example.com'],
        ]);

        $aluno->refresh();
        $this->assertSame('joao.tutory@example.com', $aluno->email);
        $this->assertSame('2002', $aluno->tutory_id);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'E-mail divergente') && str_contains($m, 'joao.plataforma@example.com')
        ));
    }

    public function test_mesmo_nome_com_tutory_id_diferente_atualiza_o_cadastro(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '27',
            'nome' => 'Amanda Carvalho Vieira',
            'email' => 'uni.amandav01@gmail.com',
            'recebe_email' => true,
            'ativo' => false,
        ]);
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $resultado = $sync->sincronizarLista([
            ['id' => '377712', 'nome' => 'Amanda Carvalho Vieira', 'email' => 'uni.amandav01@gmail.com'],
        ]);

        $this->assertSame(0, $resultado['criados']);
        $this->assertSame(0, $resultado['pulados']);
        $this->assertSame(1, $resultado['atualizados']);
        $this->assertSame(1, Aluno::query()->count());
        $aluno->refresh();
        $this->assertSame('377712', $aluno->tutory_id);
        $this->assertTrue($aluno->ativo);
        $this->assertSame('uni.amandav01@gmail.com', $aluno->email);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'tutory_id divergente') && str_contains($m, '27') && str_contains($m, '377712')
        ));
    }

    public function test_inativa_pelo_nome_quando_o_tutory_id_mudou(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '27',
            'nome' => 'Amanda Carvalho Vieira',
            'email' => 'uni.amandav01@gmail.com',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});
        $sync->atualizarInativos([
            ['id' => '377712', 'nome' => 'Amanda Carvalho Vieira', 'email' => 'uni.amandav01@gmail.com'],
        ]);

        $this->assertFalse($aluno->fresh()->ativo);
        $this->assertSame(1, Aluno::query()->count());
    }

    public function test_log_quando_nome_duplicado_na_edicao(): void
    {
        Aluno::create([
            'nome' => 'Carla A',
            'email' => 'carla.a@example.com',
            'recebe_email' => true,
        ]);
        $alvo = Aluno::create([
            'tutory_id' => '4004',
            'nome' => 'Carla B',
            'email' => 'carla.b@example.com',
            'recebe_email' => true,
        ]);
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $resultado = $sync->sincronizarLista([
            ['id' => '4004', 'nome' => 'Carla A', 'email' => 'carla.b@example.com'],
        ]);

        $this->assertSame(1, $resultado['pulados'] + $resultado['inalterados'] + $resultado['atualizados']);
        $alvo->refresh();
        $this->assertSame('Carla B', $alvo->nome);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'Nome duplicado ao editar') && str_contains($m, 'Carla A')
        ));
    }

    public function test_casa_por_email_mesmo_com_nome_diferente(): void
    {
        Aluno::create([
            'nome' => 'Nome Antigo',
            'email' => 'mesmo@example.com',
            'recebe_email' => false,
        ]);
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});
        $sync->sincronizarLista([
            ['id' => '5005', 'nome' => 'Nome Tutory', 'email' => 'mesmo@example.com'],
        ]);

        $this->assertSame(1, Aluno::query()->count());
        $aluno = Aluno::query()->first();
        $this->assertSame('Nome Tutory', $aluno->nome);
        $this->assertTrue($aluno->recebe_email);
        $this->assertSame('5005', $aluno->tutory_id);
    }

    public function test_ignora_aluno_teste_e_nao_cadastra(): void
    {
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $resultado = $sync->sincronizarLista([
            ['id' => '9009', 'nome' => 'Aluno teste', 'email' => 'aluno.teste@example.com'],
            ['id' => '9010', 'nome' => 'Aluno  Teste', 'email' => 'aluno.teste2@example.com'],
            ['id' => '1001', 'nome' => 'Maria Silva', 'email' => 'maria@example.com'],
        ]);

        $this->assertSame(1, $resultado['criados']);
        $this->assertSame(2, $resultado['pulados']);
        $this->assertSame(1, Aluno::query()->count());
        $this->assertSame('Maria Silva', Aluno::query()->first()->nome);
        $this->assertSame(0, Aluno::query()->where('email', 'aluno.teste@example.com')->count());
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'Aluno teste') && str_contains($m, 'não é cadastrado')
        ));
    }

    public function test_nao_cadastra_inativo_que_nao_existe_e_inativa_quem_ja_existe(): void
    {
        $existente = Aluno::create([
            'tutory_id' => '1001',
            'nome' => 'Maria Silva',
            'email' => 'maria@example.com',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});

        $criados = $sync->sincronizarLista([
            ['id' => '2002', 'nome' => 'João Novo', 'email' => 'joao@example.com'],
        ]);
        $sync->atualizarInativos([
            ['id' => '1001', 'nome' => 'Maria Silva', 'email' => 'maria@example.com'],
            ['id' => '3003', 'nome' => 'Fora do Portal', 'email' => 'fora@example.com'],
        ]);

        $this->assertSame(1, $criados['criados']);
        $this->assertTrue(Aluno::query()->where('email', 'joao@example.com')->first()->ativo);
        $this->assertFalse($existente->fresh()->ativo);
        $this->assertNull(Aluno::query()->where('email', 'fora@example.com')->first());
    }

    public function test_sincronizacao_ignora_a_professora(): void
    {
        $mentora = Aluno::create([
            'tutory_id' => '9001',
            'nome' => 'Nayara Oliveira',
            'email' => 'nayara@missaonomeacao.com.br',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});

        $resultado = $sync->sincronizarLista([
            ['id' => '9001', 'nome' => 'Nayara Oliveira', 'email' => 'nayara.nova@example.com'],
            ['id' => '9002', 'nome' => 'Nayara Oliveira', 'email' => 'outra@example.com'],
        ]);
        $sync->atualizarInativos([
            ['id' => '9001', 'nome' => 'Nayara Oliveira', 'email' => 'nayara@missaonomeacao.com.br'],
        ]);

        $this->assertSame(0, $resultado['criados']);
        $this->assertSame(2, $resultado['pulados']);
        $this->assertSame(1, Aluno::query()->count());
        $this->assertTrue($mentora->fresh()->ativo);
        $this->assertSame('nayara@missaonomeacao.com.br', $mentora->fresh()->email);
    }

    public function test_telefone_da_tutory_prevalece_e_ausencia_nao_apaga(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '404960',
            'nome' => 'Edileusa Pires',
            'email' => 'edileusa@example.com',
            'telefone' => '61999990000',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $sync->sincronizarLista([
            [
                'id' => '404960',
                'nome' => 'Edileusa Pires',
                'email' => 'edileusa@example.com',
                'telefone' => '5561991238860',
            ],
        ]);

        $this->assertSame('5561991238860', $aluno->fresh()->telefone);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'Telefone divergente') && str_contains($m, '5561991238860')
        ));

        $sync->sincronizarLista([
            ['id' => '404960', 'nome' => 'Edileusa Pires', 'email' => 'edileusa@example.com'],
        ]);
        $this->assertSame('5561991238860', $aluno->fresh()->telefone);

        $sync->sincronizarLista([
            [
                'id' => '404960',
                'nome' => 'Edileusa Pires',
                'email' => 'edileusa@example.com',
                'telefone' => null,
            ],
        ]);
        $this->assertNull($aluno->fresh()->telefone);
    }

    public function test_cadastra_ativo_com_telefone_da_tutory(): void
    {
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});
        $resultado = $sync->sincronizarLista([
            [
                'id' => '404960',
                'nome' => 'Edileusa Pires',
                'email' => 'edileusa@example.com',
                'telefone' => '5561991238860',
            ],
        ]);

        $this->assertSame(1, $resultado['criados']);
        $this->assertSame('5561991238860', Aluno::query()->first()->telefone);
    }

    public function test_inativar_nao_altera_o_telefone(): void
    {
        $aluno = Aluno::create([
            'tutory_id' => '1001',
            'nome' => 'Maria Silva',
            'email' => 'maria@example.com',
            'telefone' => '5561991238860',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $sync = new SincronizarAlunosTutory(logger: static function (): void {});
        $sync->atualizarInativos([
            ['id' => '1001', 'nome' => 'Maria Silva', 'email' => 'maria@example.com'],
        ]);

        $aluno->refresh();
        $this->assertFalse($aluno->ativo);
        $this->assertSame('5561991238860', $aluno->telefone);
    }

    public function test_pula_sem_email(): void
    {
        $logs = [];
        $sync = new SincronizarAlunosTutory(logger: function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $resultado = $sync->sincronizarLista([
            ['id' => '6006', 'nome' => 'Sem Email', 'email' => ''],
        ]);

        $this->assertSame(1, $resultado['pulados']);
        $this->assertSame(0, Aluno::query()->count());
        $this->assertTrue(collect($logs)->contains(fn (string $m) => str_contains($m, 'e-mail ausente')));
    }
}
