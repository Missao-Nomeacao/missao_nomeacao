<?php

namespace Tests\Feature;

use App\Enums\AcaoAcompanhamento;
use App\Models\Aluno;
use App\Models\ContatoAluno;
use App\Models\User;
use App\Services\Desempenho\AvaliadorDesempenho;
use Carbon\Carbon;
use Database\Seeders\ParametrosDesempenhoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_visitante_nao_acessa_o_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

        $aluno = $this->aluno(['nome' => 'Visitante Bloqueado']);
        $this->get(route('admin.dashboard.contatos.agendar', $aluno))->assertRedirect(route('login'));
    }

    public function test_painel_mostra_ativos_inativos_e_total(): void
    {
        $user = User::factory()->create();
        $this->aluno(['nome' => 'Ativo Um', 'ativo' => true]);
        $this->aluno(['nome' => 'Ativo Dois', 'ativo' => true]);
        $this->aluno(['nome' => 'Inativo Um', 'ativo' => false]);

        $html = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/2\s*<\/span>\s*<span class="block text-sm text-gray-600">alunos ativos/', $html);
        $this->assertMatchesRegularExpression('/1\s*<\/span>\s*<span class="block text-sm text-gray-600">alunos inativos/', $html);
        $this->assertMatchesRegularExpression('/3\s*<\/span>\s*<span class="block text-sm text-gray-600">total de alunos/', $html);
    }

    public function test_dashboard_filtra_inativos_e_ordena_por_nome(): void
    {
        $user = User::factory()->create();
        $ana = $this->aluno(['nome' => 'Ana Inativa', 'ativo' => false]);
        $this->aluno(['nome' => 'Zeca Ativo', 'ativo' => true]);
        $this->aluno(['nome' => 'Bruno Ativo', 'ativo' => true]);

        $html = $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Inativos')
            ->getContent();
        $posBruno = strpos($html, 'Bruno Ativo');
        $posZeca = strpos($html, 'Zeca Ativo');
        $posAna = strpos($html, 'Ana Inativa');
        $this->assertNotFalse($posBruno);
        $this->assertNotFalse($posZeca);
        $this->assertNotFalse($posAna);
        $this->assertTrue($posAna < $posBruno && $posBruno < $posZeca);

        $soInativos = $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'status' => 'inativos', 'aluno' => $ana->id]))
            ->assertOk()
            ->assertSee('Ana Inativa')
            ->assertSee('text-gray-700">Restabelecer contato', false)
            ->assertSee('text-gray-700">'."\n".'                Restabelecer contato', false)
            ->assertSee('Plano de estudos encerrado')
            ->assertDontSee('Plano de estudos encerrado</span>', false)
            ->assertDontSee('Bruno Ativo')
            ->assertDontSee('Zeca Ativo');
    }

    public function test_dashboard_ordena_somente_por_nome(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Zeca Parabens',
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
            'prev_performance' => 'Brigando com a constância',
            'prev_performance_codigo' => 'brigando',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'prev_question_volume' => 'Volume suficiente',
            'prev_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
            'prev_accuracy_rate' => 'Mediano',
            'prev_accuracy_rate_codigo' => 'mediano',
        ]);
        $this->aluno([
            'nome' => 'Ana Intervir',
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);
        $this->aluno(['nome' => 'Bruno Ok', 'ativo' => true]);
        $this->aluno(['nome' => 'Jhullya', 'ativo' => true]);
        $this->aluno([
            'nome' => 'Karol',
            'ativo' => true,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Amanda Inativa',
            'ativo' => false,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas']))
            ->assertOk()
            ->assertSee('data-acao="parabenizar"', false)
            ->assertSee('data-acao="intervir"', false)
            ->assertSee('data-acao="ok"', false)
            ->getContent();

        $posParabenizar = strpos($html, 'Zeca Parabens');
        $posIntervir = strpos($html, 'Ana Intervir');
        $posOk = strpos($html, 'Bruno Ok');
        $posKarol = strpos($html, 'Karol');
        $posJhullya = strpos($html, 'Jhullya');
        $posInativa = strpos($html, 'Amanda Inativa');
        $this->assertNotFalse($posParabenizar);
        $this->assertNotFalse($posIntervir);
        $this->assertNotFalse($posOk);
        $this->assertNotFalse($posKarol);
        $this->assertNotFalse($posJhullya);
        $this->assertNotFalse($posInativa);
        $this->assertTrue($posInativa < $posIntervir);
        $this->assertTrue($posIntervir < $posOk);
        $this->assertTrue($posOk < $posJhullya);
        $this->assertTrue($posJhullya < $posKarol);
        $this->assertTrue($posKarol < $posParabenizar);
    }

    public function test_perfil_da_mentora_nao_entra_no_dashboard(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Nayara Oliveira',
            'email' => 'nayara@missaonomeacao.com.br',
            'recebe_email' => true,
            'ativo' => true,
        ]);
        $this->aluno(['nome' => 'Maria Silva', 'ativo' => true]);

        $html = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Nayara Oliveira', $html);
        $this->assertStringContainsString('Maria Silva', $html);
        $this->assertMatchesRegularExpression('/1\s*<\/span>\s*<span class="block text-sm text-gray-600">alunos ativos/', $html);
        $this->assertMatchesRegularExpression('/1\s*<\/span>\s*<span class="block text-sm text-gray-600">total de alunos/', $html);
    }

    public function test_hierarquia_deixa_o_aluno_somente_em_intervir_com_todos_os_motivos(): void
    {
        $user = User::factory()->create(['name' => 'Nayara Oliveira']);
        $aluno = $this->aluno([
            'nome' => 'João Almeida',
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Crítico',
            'last_accuracy_rate_codigo' => 'critico',
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.dashboard', ['aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('Olá, Nayara!')
            ->assertSee('João Almeida')
            ->assertSee('O que deve ser destacado neste acompanhamento?')
            ->assertSee('Todos os itens do acompanhamento foram abordados?')
            ->assertSee('Constância crítica')
            ->assertSee('Desempenho em questões: baixo')
            ->assertSee('data-motivo="constancia_critica"', false)
            ->assertSee('data-acao="intervir"', false)
            ->assertDontSee('data-acao="marcar_presenca"', false)
            ->assertDontSee('data-acao="parabenizar"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-acao="intervir"'));
    }

    public function test_evolucao_sem_piora_entra_em_parabenizar(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Bruno Silva',
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
            'prev_performance' => 'Brigando com a constância',
            'prev_performance_codigo' => 'brigando',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'prev_question_volume' => 'Volume suficiente',
            'prev_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
            'prev_accuracy_rate' => 'Mediano',
            'prev_accuracy_rate_codigo' => 'mediano',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-acao="parabenizar"', false)
            ->assertSee('Constância evoluiu: Brigando com a constância → Bom');
    }

    public function test_assunto_baixo_mostra_a_ponte_com_o_protocolo_de_resgate(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Maria Costa',
            'assuntos_detalhe' => [[
                'disciplina' => 'Administrativo',
                'assunto' => 'Atos Administrativos',
                'percentual' => 68,
                'faixa' => 'abaixo_media',
                'faixa_nome' => 'Abaixo da média',
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('Administrativo · Atos Administrativos: desempenho baixo')
            ->assertSee('Ponte com o Protocolo de Resgate')
            ->assertSee('data-acao="marcar_presenca"', false);
    }

    public function test_ficha_abre_whatsapp_do_aluno_em_nova_aba(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Edileusa Pires',
            'telefone' => '55 (61) 9912-38860',
        ]);

        $abrir = route('admin.dashboard.whatsapp', $aluno);
        $html = $this->actingAs($user)
            ->get(route('admin.dashboard', ['aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('Registrar contato')
            ->assertSee('Conversar com aluno')
            ->assertSee('href="https://web.whatsapp.com/send?phone=5561991238860&amp;text=Ol%C3%A1"', false)
            ->assertSee('data-abrir="'.$abrir.'"', false)
            ->assertSee("window.open(this.dataset.abrir,'missao-whatsapp')", false)
            ->getContent();
        $this->assertTrue(strpos($html, 'Registrar contato') < strpos($html, 'Conversar com aluno'));

        $this->actingAs($user)
            ->get($abrir)
            ->assertOk()
            ->assertSee('location.replace("https:\\/\\/web.whatsapp.com\\/send?phone=5561991238860\\u0026text=Ol%C3%A1")', false);

        $semTelefone = $this->aluno(['nome' => 'Sem Telefone']);
        $this->actingAs($user)
            ->get(route('admin.dashboard', ['aluno' => $semTelefone->id]))
            ->assertOk()
            ->assertDontSee('Conversar com aluno')
            ->assertDontSee('https://web.whatsapp.com/send');

        $this->actingAs($user)
            ->get(route('admin.dashboard.whatsapp', $semTelefone))
            ->assertNotFound();
    }

    public function test_registrar_contato_conclui_a_acao_academica(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Ana Souza',
            'last_accuracy_rate' => 'Alerta',
            'last_accuracy_rate_codigo' => 'alerta',
        ]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Combinamos uma revisão de questões.',
                'itens_abordados' => '1',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno));

        $aluno->refresh();
        $this->assertSame(AcaoAcompanhamento::Ok, $aluno->acao_resolvida);
        $this->assertNotNull($aluno->ultimo_contato_em);
        $this->assertNull($aluno->proximo_contato_em);
        $this->assertSame('Combinamos uma revisão de questões.', $aluno->ultima_observacao);
        $this->assertSame(1, ContatoAluno::query()->count());

        $this->actingAs($user)
            ->get(route('admin.dashboard.contatos.agendar', $aluno))
            ->assertOk()
            ->assertSee('Deseja agendar um contato para Ana Souza?')
            ->assertSee('Não, agendar para 06/10/2026')
            ->assertSee('15 dias corridos contando hoje');

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.agendar.store', $aluno), [
                'decisao' => 'nao',
            ])
            ->assertRedirect(route('admin.dashboard', ['aluno' => $aluno->id]));

        $this->assertSame('2026-10-06', $aluno->fresh()->proximo_contato_em?->toDateString());

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-aluno="'.$aluno->id.'"', false);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'concluidas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('Ana Souza')
            ->assertSee('Combinamos uma revisão de questões.')
            ->assertSee('Ações do mentor concluídas neste acompanhamento')
            ->assertSee('data-acao="ok"', false)
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertSee('Concluída');
    }

    public function test_observacao_e_confirmacao_dos_itens_sao_obrigatorias(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno(['nome' => 'Paula Dias']);
        $ficha = route('admin.dashboard', ['aluno' => $aluno->id]);

        $this->actingAs($user)
            ->from($ficha)
            ->post(route('admin.dashboard.contatos.store', $aluno), [])
            ->assertRedirect($ficha)
            ->assertSessionHasErrors(['observacao', 'itens_abordados']);

        $this->actingAs($user)
            ->from($ficha)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => '   ',
                'itens_abordados' => '1',
            ])
            ->assertRedirect($ficha)
            ->assertSessionHasErrors('observacao');

        $this->assertSame(0, ContatoAluno::query()->count());
        $this->assertNull($aluno->fresh()->acao_resolvida);

        $this->actingAs($user)
            ->get($ficha)
            ->assertOk()
            ->assertSee('O que deve ser destacado neste acompanhamento?')
            ->assertSee('Todos os itens do acompanhamento foram abordados?')
            ->assertSee('Data do contato')
            ->assertSee('name="ocorrido_em"', false)
            ->assertSee('max="2026-09-22"', false)
            ->assertSee('value="2026-09-22"', false)
            ->assertSee('name="observacao" rows="3" required', false)
            ->assertSee('Sim, todos foram abordados')
            ->assertSee('Não, ainda não');
    }

    public function test_registrar_contato_aceita_data_personalizada(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Carla Data',
            'proximo_contato_em' => '2026-09-18',
        ]);
        $ficha = route('admin.dashboard', ['aluno' => $aluno->id]);

        $this->actingAs($user)
            ->from($ficha)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Contato feito na sexta.',
                'itens_abordados' => '1',
                'ocorrido_em' => '2026-09-25',
            ])
            ->assertRedirect($ficha)
            ->assertSessionHasErrors('ocorrido_em');

        $this->assertSame(0, ContatoAluno::query()->count());

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Contato feito na sexta.',
                'itens_abordados' => '1',
                'ocorrido_em' => '2026-09-18',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno));

        $aluno->refresh();
        $this->assertSame('2026-09-18', $aluno->ultimo_contato_em?->toDateString());
        $this->assertNull($aluno->proximo_contato_em);
        $this->assertSame('2026-09-18', ContatoAluno::query()->first()?->ocorrido_em?->toDateString());
    }

    public function test_contato_deixa_acao_ok_ate_agendamento_vencido_ou_relatorio_novo(): void
    {
        $this->seed(ParametrosDesempenhoSeeder::class);
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Rita Critica',
            'ativo' => true,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Conversamos sobre a constância.',
                'itens_abordados' => '1',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno));

        $this->assertSame(AcaoAcompanhamento::Ok, $aluno->fresh()->acao_resolvida);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.agendar.store', $aluno), [
                'decisao' => 'sim',
                'proximo_contato_em' => '2026-09-25',
            ])
            ->assertRedirect(route('admin.dashboard', ['aluno' => $aluno->id]));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'concluidas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('data-acao="ok"', false)
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertDontSee('data-acao="intervir"', false);

        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'concluidas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertDontSee('data-acao="marcar_presenca"', false);

        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('data-acao="marcar_presenca"', false)
            ->assertSee('data-acao-ficha="marcar_presenca"', false)
            ->assertSee('Nenhum contato registrado após a data agendada (25/09/2026)')
            ->assertSee('Pendente')
            ->assertDontSee('data-acao="intervir"', false);

        $aluno->refresh();
        $aluno->aplicarAvaliacaoDesempenho((new AvaliadorDesempenho)->avaliarRelatorio([
            'nome' => $aluno->nome,
            'dias_analisados' => 15,
            'dias_estudados' => 2,
            'dias_falhados' => 13,
            'total_questoes' => 40,
            'percentual_acertos' => 40,
        ]), '2026-09-2');

        $this->assertNull($aluno->fresh()->acao_resolvida);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('data-acao="intervir"', false)
            ->assertSee('data-acao-ficha="intervir"', false)
            ->assertSee('Constância crítica');
    }

    public function test_contato_em_aluno_inativo_tambem_deixa_a_acao_ok(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Inativa Contatada',
            'ativo' => false,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Plano encerrado, combinamos o retorno.',
                'itens_abordados' => '1',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $aluno->id]))
            ->assertOk()
            ->assertSee('data-acao="ok"', false)
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertDontSee('data-acao="restabelecer_contato"', false);
    }

    public function test_sim_agenda_a_data_informada_pelo_mentor(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Bruno Silva',
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $aluno), [
                'observacao' => 'Alinhamos o próximo passo.',
                'itens_abordados' => '1',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno));

        $this->actingAs($user)
            ->from(route('admin.dashboard.contatos.agendar', $aluno))
            ->post(route('admin.dashboard.contatos.agendar.store', $aluno), [
                'decisao' => 'sim',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $aluno))
            ->assertSessionHasErrors('proximo_contato_em');

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.agendar.store', $aluno), [
                'decisao' => 'sim',
                'proximo_contato_em' => '2026-10-01',
            ])
            ->assertRedirect(route('admin.dashboard', ['aluno' => $aluno->id]))
            ->assertSessionHas('success', 'Próximo contato de Bruno Silva agendado para 01/10/2026.');

        $this->assertSame('2026-10-01', $aluno->fresh()->proximo_contato_em?->toDateString());
    }

    public function test_tempo_sem_contato_nao_define_marcar_presenca_e_vira_observacao(): void
    {
        $user = User::factory()->create();
        $semFaixa = $this->aluno([
            'nome' => 'Luíza Alves',
            'created_at' => Carbon::parse('2026-09-01 09:00:00'),
        ]);
        $evoluiu = $this->aluno([
            'nome' => 'Clara Evoluiu',
            'created_at' => Carbon::parse('2026-09-01 09:00:00'),
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
            'prev_performance' => 'Brigando com a constância',
            'prev_performance_codigo' => 'brigando',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'prev_question_volume' => 'Volume suficiente',
            'prev_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
            'prev_accuracy_rate' => 'Mediano',
            'prev_accuracy_rate_codigo' => 'mediano',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $semFaixa->id]))
            ->assertOk()
            ->assertSee('Luíza Alves')
            ->assertSee('data-acao="ok"', false)
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertSee('>Observação</dt>', false)
            ->assertSee('21 dias sem contato')
            ->assertDontSee('data-acao="marcar_presenca"', false);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $evoluiu->id]))
            ->assertOk()
            ->assertSee('data-acao="parabenizar"', false)
            ->assertSee('data-acao-ficha="parabenizar"', false)
            ->assertSee('Constância evoluiu: Brigando com a constância → Bom')
            ->assertSee('21 dias sem contato')
            ->assertDontSee('data-acao="marcar_presenca"', false);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['foco' => 'sem_contato', 'situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Luíza Alves')
            ->assertSee('Clara Evoluiu');

        $baixo = $this->aluno([
            'nome' => 'Dora Baixa',
            'created_at' => Carbon::parse('2026-09-01 09:00:00'),
            'last_accuracy_rate' => 'Alerta',
            'last_accuracy_rate_codigo' => 'alerta',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $baixo->id]))
            ->assertOk()
            ->assertSee('data-acao-ficha="marcar_presenca"', false)
            ->assertSee('Desempenho em questões: baixo')
            ->assertSee('21 dias sem contato');
    }

    public function test_remarcar_data_grava_a_nova_e_o_cancelar_preserva_a_anterior(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno([
            'nome' => 'Helena Agenda',
            'proximo_contato_em' => '2026-10-10',
        ]);
        $ficha = route('admin.dashboard', ['situacao' => 'todas', 'aluno' => $aluno->id]);

        $this->actingAs($user)
            ->get($ficha)
            ->assertOk()
            ->assertSee('Remarcar data')
            ->assertSee('Cancelar mantém essa data.')
            ->assertSee('id="cancelar-remarcar-data"', false)
            ->assertSee('data-data-original="2026-10-10"', false)
            ->assertSee('restaurarDataAnterior', false)
            ->assertDontSee('Agendar acompanhamento');

        $this->actingAs($user)
            ->from($ficha)
            ->post(route('admin.dashboard.agenda.store', $aluno), [
                'proximo_contato_em' => '2026-10-20',
                'remarcar' => '1',
            ])
            ->assertRedirect($ficha)
            ->assertSessionHas('success', 'Próximo contato remarcado para 20/10/2026.');

        $this->assertSame('2026-10-20', $aluno->fresh()->proximo_contato_em?->toDateString());
    }

    public function test_agendar_para_hoje_entra_na_lista_do_dia(): void
    {
        $user = User::factory()->create();
        $aluno = $this->aluno(['nome' => 'Pedro Lima']);

        $this->actingAs($user)
            ->post(route('admin.dashboard.agenda.store', $aluno), [
                'proximo_contato_em' => '2026-09-22',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['foco' => 'agenda_hoje']))
            ->assertOk()
            ->assertSee('Pedro Lima')
            ->assertSee('Contato programado para hoje');
    }

    public function test_periodo_novo_guarda_a_faixa_anterior_e_reabre_a_acao(): void
    {
        $this->seed(ParametrosDesempenhoSeeder::class);
        $avaliador = new AvaliadorDesempenho;
        $aluno = $this->aluno([
            'nome' => 'Carla Mendes',
            'acao_resolvida' => AcaoAcompanhamento::Intervir->value,
            'acao_resolvida_assinatura' => 'assinatura-antiga',
        ]);

        $aluno->aplicarAvaliacaoDesempenho($avaliador->avaliarRelatorio([
            'nome' => $aluno->nome,
            'dias_analisados' => 15,
            'dias_estudados' => 2,
            'dias_falhados' => 13,
            'total_questoes' => 40,
            'percentual_acertos' => 40,
        ]), '2026-09-1');

        $aluno->refresh();
        $this->assertSame('Crítico', $aluno->last_performance);
        $this->assertSame('critico', $aluno->last_performance_codigo);
        $this->assertNull($aluno->prev_performance);
        $this->assertNull($aluno->acao_resolvida);

        $aluno->aplicarAvaliacaoDesempenho($avaliador->avaliarRelatorio([
            'nome' => $aluno->nome,
            'dias_analisados' => 15,
            'dias_estudados' => 12,
            'dias_falhados' => 3,
            'total_questoes' => 180,
            'percentual_acertos' => 82,
            'assuntos' => [
                ['disciplina' => 'DIR ADM', 'assunto' => 'Atos Administrativos', 'percentual' => 58],
            ],
        ]), '2026-09-2');

        $aluno->refresh();
        $this->assertSame('Crítico', $aluno->prev_performance);
        $this->assertSame('Bom', $aluno->last_performance);
        $this->assertSame('Crítico e inconclusivo', $aluno->prev_question_volume);
        $this->assertSame('Volume suficiente', $aluno->last_question_volume);
        $this->assertSame('Muito bom', $aluno->last_accuracy_rate);
        $this->assertSame('Atos Administrativos', $aluno->assuntos_detalhe[0]['assunto'] ?? null);
        $this->assertSame('critico', $aluno->assuntos_detalhe[0]['faixa'] ?? null);

        $aluno->aplicarAvaliacaoDesempenho($avaliador->avaliarRelatorio([
            'nome' => $aluno->nome,
            'dias_analisados' => 15,
            'dias_estudados' => 14,
            'dias_falhados' => 1,
            'total_questoes' => 220,
            'percentual_acertos' => 88,
        ]), '2026-09-2');

        $aluno->refresh();
        $this->assertSame('Crítico', $aluno->prev_performance);
        $this->assertSame('Bom', $aluno->last_performance);
    }

    public function test_limpar_filtros_volta_a_lista_completa(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Andreza Crítica',
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Helena Em Dia',
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard', [
                'foco' => 'intervir',
                'acao' => 'intervir',
                'parametro' => 'constancia',
                'busca' => 'Andreza',
                'situacao' => 'todas',
            ]))
            ->assertOk()
            ->assertSee('Limpar filtros')
            ->assertSee('Andreza Crítica')
            ->assertDontSee('Helena Em Dia');

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Limpar filtros')
            ->assertSee('Andreza Crítica')
            ->assertSee('Helena Em Dia');
    }

    public function test_alunos_sem_acao_prioritaria_aparecem_ok(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Andreza Crítica',
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Helena Em Dia',
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
        ]);
        $this->aluno([
            'nome' => 'Caio Presença',
            'last_accuracy_rate' => 'Alerta',
            'last_accuracy_rate_codigo' => 'alerta',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Andreza Crítica')
            ->assertSee('Helena Em Dia')
            ->assertSee('Caio Presença')
            ->assertSee('name="acao"', false)
            ->assertSee('Todas as ações (3)')
            ->assertSee('Ok (1)')
            ->assertSee('Marcar presença (1)')
            ->assertSee('Intervir (1)')
            ->assertSee('Constância: Bom')
            ->assertDontSee('ring-2 ring-primary-light');

        $legado = $this->aluno([
            'nome' => 'Legado Em Dia',
            'last_performance' => 'Bom',
            'last_performance_codigo' => 'bom',
        ]);
        Aluno::query()->whereKey($legado->id)->update(['acao_resolvida' => 'em_dia']);
        $this->assertSame(AcaoAcompanhamento::Ok, $legado->fresh()->acao_resolvida);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['acao' => 'ok']))
            ->assertOk()
            ->assertSee('Helena Em Dia')
            ->assertDontSee('Andreza Crítica')
            ->assertDontSee('Caio Presença')
            ->assertSee('value="ok" selected', false);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['acao' => 'marcar_presenca']))
            ->assertOk()
            ->assertSee('Caio Presença')
            ->assertDontSee('Andreza Crítica')
            ->assertDontSee('Helena Em Dia');
    }

    public function test_aluno_com_menos_de_15_dias_fica_novato_e_entra_no_filtro(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Ana Novata',
            'created_at' => Carbon::parse('2026-09-20 09:00:00'),
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
            'last_question_volume' => 'Volume crítico',
            'last_question_volume_codigo' => 'volume_critico',
            'last_accuracy_rate' => 'Crítico',
            'last_accuracy_rate_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Bruno Veterano',
            'created_at' => Carbon::parse('2026-08-01 09:00:00'),
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
            'last_question_volume' => 'Volume suficiente',
            'last_question_volume_codigo' => 'volume_suficiente',
            'last_accuracy_rate' => 'Mediano',
            'last_accuracy_rate_codigo' => 'mediano',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Alunos novatos (1)')
            ->assertSee('data-acao="aluno_novato"', false)
            ->assertSee('Ana Novata')
            ->assertSee('Menos de 15 dias: as métricas do relatório não são analisadas.');

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['acao' => 'aluno_novato', 'situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Ana Novata')
            ->assertDontSee('Bruno Veterano');

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['acao' => 'intervir', 'situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Bruno Veterano')
            ->assertDontSee('Ana Novata');
    }

    public function test_contato_com_aluno_novato_deixa_acao_ok_e_observacao_na_ficha(): void
    {
        $user = User::factory()->create();
        $ana = $this->aluno([
            'nome' => 'Ana Novata',
            'created_at' => Carbon::parse('2026-09-20 09:00:00'),
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
            'last_question_volume' => 'Volume crítico',
            'last_question_volume_codigo' => 'volume_critico',
            'last_accuracy_rate' => 'Crítico',
            'last_accuracy_rate_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Lia Sem Contato',
            'created_at' => Carbon::parse('2026-09-20 09:00:00'),
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.store', $ana), [
                'observacao' => 'Primeiro contato de boas-vindas.',
                'itens_abordados' => '1',
            ])
            ->assertRedirect(route('admin.dashboard.contatos.agendar', $ana));

        $this->actingAs($user)
            ->post(route('admin.dashboard.contatos.agendar.store', $ana), [
                'decisao' => 'sim',
                'proximo_contato_em' => '2026-09-25',
            ])
            ->assertRedirect(route('admin.dashboard', ['aluno' => $ana->id]));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'concluidas', 'aluno' => $ana->id]))
            ->assertOk()
            ->assertSee('data-acao="ok"', false)
            ->assertSee('data-acao-ficha="ok"', false)
            ->assertSee('>Observação</dt>', false)
            ->assertSee('Cadastro há 2 dias. Menos de 15 dias: as métricas do relatório não são analisadas.')
            ->assertSee('Primeiro contato de boas-vindas.')
            ->assertDontSee('data-acao="aluno_novato"', false)
            ->assertDontSee('data-acao="intervir"', false);

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['acao' => 'aluno_novato', 'situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Alunos novatos (1)')
            ->assertSee('Lia Sem Contato')
            ->assertDontSee('Ana Novata');

        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', 'America/Sao_Paulo'));

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'busca' => 'Ana Novata', 'aluno' => $ana->id]))
            ->assertOk()
            ->assertSee('data-acao="marcar_presenca"', false)
            ->assertSee('data-acao-ficha="marcar_presenca"', false)
            ->assertSee('Nenhum contato registrado após a data agendada (25/09/2026)')
            ->assertSee('Cadastro há 6 dias. Menos de 15 dias: as métricas do relatório não são analisadas.')
            ->assertDontSee('data-acao="intervir"', false)
            ->assertDontSee('data-acao="aluno_novato"', false);
    }

    public function test_inativo_sai_de_intervir_e_entra_em_restabelecer_contato(): void
    {
        $user = User::factory()->create();
        $this->aluno([
            'nome' => 'Georgia Ativa',
            'ativo' => true,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);
        $this->aluno([
            'nome' => 'Isadora Inativa',
            'ativo' => false,
            'last_performance' => 'Crítico',
            'last_performance_codigo' => 'critico',
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas']))
            ->assertOk()
            ->assertSee('Intervir (1)')
            ->assertSee('Restabelecer contato (1)')
            ->assertSee('Georgia Ativa')
            ->assertSee('Isadora Inativa')
            ->assertSee('Plano de estudos encerrado')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/1\s*<\/span>\s*<span class="mt-1 block text-sm leading-5">intervenções pendentes/',
            $html,
        );

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'acao' => 'intervir']))
            ->assertOk()
            ->assertSee('Georgia Ativa')
            ->assertSee('value="intervir" selected', false)
            ->assertDontSee('Isadora Inativa');

        $this->actingAs($user)
            ->get(route('admin.dashboard', ['situacao' => 'todas', 'acao' => 'restabelecer_contato']))
            ->assertOk()
            ->assertSee('Isadora Inativa')
            ->assertSee('Restabelecer contato')
            ->assertSee('value="restabelecer_contato" selected', false)
            ->assertDontSee('Georgia Ativa');
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function aluno(array $dados): Aluno
    {
        // 15 dias antes do relógio do teste: fora de aluno novato e ainda sem a observação de tempo sem contato.
        $criadoEm = $dados['created_at'] ?? Carbon::parse('2026-09-07 09:00:00');
        unset($dados['created_at'], $dados['updated_at']);

        $aluno = Aluno::create(array_merge([
            'nome' => 'Aluno Teste',
            'email' => fake()->unique()->safeEmail(),
            'recebe_email' => false,
        ], $dados));
        $aluno->forceFill([
            'created_at' => $criadoEm,
            'updated_at' => $criadoEm,
        ])->save();

        return $aluno->fresh();
    }
}
