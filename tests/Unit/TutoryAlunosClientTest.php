<?php

namespace Tests\Unit;

use App\Services\Tutory\TutoryAlunosClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class TutoryAlunosClientTest extends TestCase
{
    public function test_extrai_nome_id_e_email_dos_cards(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="pesquisa-aluno-container">
  <span class="pesquisa-aluno-nome">Giovanna</span>
  <a class="btn-generate-report" data-id="7711" href="#">Gerar</a>
  giovanna@example.com
</div>
<div class="pesquisa-aluno-container" data-email="bruno@example.com">
  <span class="pesquisa-aluno-nome">Bruno Costa</span>
  <a class="btn-generate-report" data-id="7712" href="#">Gerar</a>
</div>
</body></html>
HTML;

        $client = new TutoryAlunosClient(static function (): void {});
        $ref = new ReflectionClass($client);
        $metodo = $ref->getMethod('parseAlunosDaPagina');
        $alunos = $metodo->invoke($client, $html);

        $this->assertSame([
            ['id' => '7711', 'nome' => 'Giovanna', 'email' => 'giovanna@example.com'],
            ['id' => '7712', 'nome' => 'Bruno Costa', 'email' => 'bruno@example.com'],
        ], $alunos);
    }

    public function test_login_e_coleta_alunos_ativos(): void
    {
        Http::fake([
            'admin.tutory.com.br/login' => Http::response('<html>ok</html>', 200),
            'admin.tutory.com.br/intent/login' => Http::response(['result' => true], 200),
            'admin.tutory.com.br/index' => Http::response("adminUser = { token: 'abc123' };", 200),
            'admin.tutory.com.br/alunos/index*' => Http::response('<html>sem cadastro</html>', 404),
            'admin.tutory.com.br/alunos/consulta*' => Http::response(
                '<html><body><div class="pesquisa-aluno-container">'
                .'<span class="pesquisa-aluno-nome">Lara</span>'
                .'<a class="btn-generate-report" data-id="88" href="#"></a>'
                .'lara@example.com'
                .'</div></body></html>',
                200
            ),
        ]);

        $client = new TutoryAlunosClient(static function (): void {});
        $client->login();
        $alunos = $client->coletarAlunosAtivos();
        $client->encerrar();

        $this->assertSame([
            ['id' => '88', 'nome' => 'Lara', 'email' => 'lara@example.com'],
        ], $alunos);
    }

    public function test_ativos_leem_o_telefone_do_cadastro_no_padrao_55_ddd_numero(): void
    {
        Http::fake([
            'admin.tutory.com.br/login' => Http::response('<html>ok</html>', 200),
            'admin.tutory.com.br/intent/login' => Http::response(['result' => true], 200),
            'admin.tutory.com.br/index' => Http::response("adminUser = { token: 'abc123' };", 200),
            'admin.tutory.com.br/alunos/consulta*' => Http::response(
                '<html><body><div class="pesquisa-aluno-container">'
                .'<span class="pesquisa-aluno-nome">Edileusa Pires</span>'
                .'<a class="dropdown-item" href="index?aid=404960">Cadastro</a>'
                .'<a class="btn-generate-report" data-id="404960" href="#"></a>'
                .'edileusa@example.com'
                .'</div></body></html>',
                200
            ),
            'admin.tutory.com.br/alunos/index*' => Http::response(
                '<html><body>'
                .'<select id="cadastroAlunoDDD" name="ddd"><option value="61" selected>61</option><option value="11">11</option></select>'
                .'<input id="cadastroAlunoTel" name="celular" value="9912-38860">'
                .'</body></html>',
                200
            ),
        ]);

        $client = new TutoryAlunosClient(static function (): void {});
        $client->login();
        $alunos = $client->coletarAlunosAtivos();
        $client->encerrar();

        $this->assertSame([
            [
                'id' => '404960',
                'nome' => 'Edileusa Pires',
                'email' => 'edileusa@example.com',
                'telefone' => '5561991238860',
            ],
        ], $alunos);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/alunos/index?aid=404960'));
    }

    public function test_desativados_nao_abrem_o_cadastro(): void
    {
        Http::fake([
            'admin.tutory.com.br/alunos/consulta*' => Http::response(
                '<html><body><div class="pesquisa-aluno-container">'
                .'<span class="pesquisa-aluno-nome">Lara</span>'
                .'<a class="dropdown-item" href="index?aid=88">Cadastro</a>'
                .'<a class="btn-generate-report" data-id="88" href="#"></a>'
                .'lara@example.com'
                .'</div></body></html>',
                200
            ),
        ]);

        $client = new TutoryAlunosClient(static function (): void {});
        $alunos = $client->coletarAlunosDesativados();
        $client->encerrar();

        $this->assertSame([
            ['id' => '88', 'nome' => 'Lara', 'email' => 'lara@example.com'],
        ], $alunos);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/alunos/index'));
    }

    public function test_ativos_usam_o_email_do_cadastro_e_nao_o_login_antigo_do_card(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/login') && ! str_contains($url, 'intent')) {
                return Http::response('<html>ok</html>', 200);
            }
            if (str_contains($url, '/intent/login')) {
                return Http::response(['result' => true], 200);
            }
            if (str_contains($url, '/index') && ! str_contains($url, '/alunos/')) {
                return Http::response("adminUser = { token: 'abc123' };", 200);
            }
            if (str_contains($url, '/alunos/consulta')) {
                return Http::response(
                    '<html><body>'
                    .'<div class="pesquisa-aluno-container">'
                    .'<span class="pesquisa-aluno-nome">Amanda Carvalho Vieira</span>'
                    .'<a class="dropdown-item" href="index?aid=377712">Cadastro</a>'
                    .'<a class="btn-generate-report" data-id="377712" href="#"></a>'
                    .'uni.amandav01@gmail.com'
                    .'</div>'
                    .'<div class="pesquisa-aluno-container">'
                    .'<span class="pesquisa-aluno-nome">Giovanna</span>'
                    .'<a class="dropdown-item" href="index?aid=7711">Cadastro</a>'
                    .'<a class="btn-generate-report" data-id="7711" href="#"></a>'
                    .'psicologa.giovanna_99@gmail.com'
                    .'</div>'
                    .'</body></html>',
                    200
                );
            }
            if (str_contains($url, 'aid=377712')) {
                return Http::response(
                    '<html><body>'
                    .'<p>login antigo uni.amandav01@gmail.com</p>'
                    .'<input id="cadastroAlunoEmail" name="email" type="email" value="uni.amandav@gmail.com">'
                    .'<select id="cadastroAlunoDDD" name="ddd"><option value="61" selected>61</option></select>'
                    .'<input id="cadastroAlunoTel" name="celular" value="9912-38860">'
                    .'</body></html>',
                    200
                );
            }
            if (str_contains($url, 'aid=7711')) {
                return Http::response(
                    '<html><body>'
                    .'<p>login antigo psicologa.giovanna_99@gmail.com</p>'
                    .'<input id="cadastroAlunoEmail" name="email" type="email" value="psicologa.giovanna99@gmail.com">'
                    .'</body></html>',
                    200
                );
            }

            return Http::response('nao encontrado', 404);
        });

        $logs = [];
        $client = new TutoryAlunosClient(function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $client->login();
        $alunos = $client->coletarAlunosAtivos();
        $client->encerrar();

        $this->assertSame([
            [
                'id' => '377712',
                'nome' => 'Amanda Carvalho Vieira',
                'email' => 'uni.amandav@gmail.com',
                'telefone' => '5561991238860',
            ],
            [
                'id' => '7711',
                'nome' => 'Giovanna',
                'email' => 'psicologa.giovanna99@gmail.com',
            ],
        ], $alunos);
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'uni.amandav01@gmail.com') && str_contains($m, 'uni.amandav@gmail.com')
        ));
        $this->assertTrue(collect($logs)->contains(
            fn (string $m) => str_contains($m, 'psicologa.giovanna_99@gmail.com') && str_contains($m, 'psicologa.giovanna99@gmail.com')
        ));
    }

    public function test_cadastro_sem_email_mantem_o_do_card(): void
    {
        Http::fake([
            'admin.tutory.com.br/login' => Http::response('<html>ok</html>', 200),
            'admin.tutory.com.br/intent/login' => Http::response(['result' => true], 200),
            'admin.tutory.com.br/index' => Http::response("adminUser = { token: 'abc123' };", 200),
            'admin.tutory.com.br/alunos/consulta*' => Http::response(
                '<html><body><div class="pesquisa-aluno-container">'
                .'<span class="pesquisa-aluno-nome">Lara</span>'
                .'<a class="dropdown-item" href="index?aid=88">Cadastro</a>'
                .'<a class="btn-generate-report" data-id="88" href="#"></a>'
                .'lara@example.com'
                .'</div></body></html>',
                200
            ),
            'admin.tutory.com.br/alunos/index*' => Http::response(
                '<html><body><select id="cadastroAlunoDDD" name="ddd"><option value="61" selected>61</option></select>'
                .'<input id="cadastroAlunoTel" name="celular" value="9912-38860"></body></html>',
                200
            ),
        ]);

        $client = new TutoryAlunosClient(static function (): void {});
        $client->login();
        $alunos = $client->coletarAlunosAtivos();
        $client->encerrar();

        $this->assertSame('lara@example.com', $alunos[0]['email']);
        $this->assertSame('5561991238860', $alunos[0]['telefone']);
    }

    public function test_monta_telefone_e_ignora_cadastro_incompleto(): void
    {
        $this->assertSame('5561991238860', TutoryAlunosClient::montarTelefone('61', '9912-38860'));
        $this->assertNull(TutoryAlunosClient::montarTelefone('', '9912-38860'));
        $this->assertNull(TutoryAlunosClient::montarTelefone('61', ''));

        $client = new TutoryAlunosClient(static function (): void {});
        $this->assertSame(
            ['encontrou' => true, 'telefone' => null],
            $client->extrairTelefoneDoCadastro(
                '<select id="cadastroAlunoDDD" name="ddd"><option value="61">61</option></select>'
                .'<input id="cadastroAlunoTel" name="celular" value="">'
            )
        );
        $this->assertSame(
            ['encontrou' => false, 'telefone' => null],
            $client->extrairTelefoneDoCadastro('<html><body>sem formulario</body></html>')
        );
        $this->assertSame(
            'uni.amandav@gmail.com',
            $client->extrairEmailDoCadastro(
                '<p>uni.amandav01@gmail.com</p>'
                .'<input id="cadastroAlunoEmail" name="email" type="email" value="uni.amandav@gmail.com">'
            )
        );
        $this->assertSame(
            'psicologa.giovanna99@gmail.com',
            $client->extrairEmailDoCadastro(
                '<p>psicologa.giovanna_99@gmail.com</p>'
                .'<input name="email" type="email" value="psicologa.giovanna99@gmail.com">'
            )
        );
        $this->assertSame('', $client->extrairEmailDoCadastro('<html>uni.amandav01@gmail.com</html>'));
        $client->encerrar();
    }
}
