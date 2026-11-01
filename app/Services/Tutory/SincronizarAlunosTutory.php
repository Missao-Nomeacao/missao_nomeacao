<?php

namespace App\Services\Tutory;

use App\Models\Aluno;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Espelha alunos ativos da Tutory na tabela local.
 *
 * Nome da Tutory prevalece. E-mail da Tutory prevalece se o aluno já existir.
 * O telefone dos ativos também prevalece, no padrão 55 + DDD + número.
 * recebe_email fica sempre true. O status local nasce true.
 * Só cadastra quem está ativo na Tutory e ainda não existe no portal.
 * Quem já existe e está em status=desativados na Tutory fica inativo.
 * Nome é único: o mesmo aluno reaproveita o cadastro local mesmo se o tutory_id mudou.
 * O cadastro "Aluno teste" da Tutory é ignorado e não entra na tabela local.
 */
class SincronizarAlunosTutory
{
    private const NOME_ALUNO_IGNORADO = 'Aluno teste';

    /** @var callable(string): void */
    private $logger;

    public function __construct(
        private ?TutoryAlunosClient $tutory = null,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $message): void {
            echo $message.PHP_EOL;
        };
        $this->tutory ??= new TutoryAlunosClient($this->logger);
    }

    /**
     * @return array{criados: int, atualizados: int, inalterados: int, pulados: int, total: int}
     */
    public function run(): array
    {
        try {
            $this->tutory->login();
            $resultado = $this->sincronizarLista($this->tutory->coletarAlunosAtivos());
            $this->atualizarInativos($this->tutory->coletarAlunosDesativados());

            return $resultado;
        } finally {
            $this->tutory->encerrar();
        }
    }

    /**
     * @param  list<array{id?: string, nome?: string, email?: string, telefone?: string|null}>  $alunosTutory
     * @return array{criados: int, atualizados: int, inalterados: int, pulados: int, total: int}
     */
    public function sincronizarLista(array $alunosTutory): array
    {
        $criados = 0;
        $atualizados = 0;
        $inalterados = 0;
        $pulados = 0;

        foreach ($alunosTutory as $origem) {
            $item = [
                'id' => trim((string) ($origem['id'] ?? '')),
                'nome' => trim((string) ($origem['nome'] ?? '')),
                'email' => mb_strtolower(trim((string) ($origem['email'] ?? ''))),
                'tem_telefone' => array_key_exists('telefone', $origem),
                'telefone' => null,
            ];
            if ($item['tem_telefone']) {
                $telefone = trim((string) ($origem['telefone'] ?? ''));
                $item['telefone'] = $telefone === '' ? null : $telefone;
            }
            $resultado = $this->sincronizarUm($item);
            match ($resultado) {
                'criado' => $criados++,
                'atualizado' => $atualizados++,
                'inalterado' => $inalterados++,
                default => $pulados++,
            };
        }

        $resumo = "Sincronização concluída: {$criados} criado(s), {$atualizados} atualizado(s), {$inalterados} inalterado(s), {$pulados} pulado(s).";
        $this->log($resumo);

        return [
            'criados' => $criados,
            'atualizados' => $atualizados,
            'inalterados' => $inalterados,
            'pulados' => $pulados,
            'total' => count($alunosTutory),
        ];
    }

    /**
     * @param  array{id: string, nome: string, email: string, tem_telefone: bool, telefone: string|null}  $origem
     */
    private function sincronizarUm(array $origem): string
    {
        $nomeTutory = $origem['nome'];
        $emailTutory = $origem['email'];
        $tutoryId = $origem['id'];

        if ($nomeTutory === '') {
            $this->log('Aluno da Tutory ignorado: nome vazio (id='.($tutoryId !== '' ? $tutoryId : '—').').');

            return 'pulado';
        }
        if ($this->isTeacher($nomeTutory, $emailTutory)) {
            $this->log('Perfil da mentora ignorado na sincronização: "'.$nomeTutory.'".');

            return 'pulado';
        }
        if ($this->eAlunoTeste($nomeTutory)) {
            $this->log('Aluno da Tutory ignorado: "'.$nomeTutory.'" não é cadastrado (id='.($tutoryId !== '' ? $tutoryId : '—').').');

            return 'pulado';
        }
        if ($emailTutory === '' || ! filter_var($emailTutory, FILTER_VALIDATE_EMAIL)) {
            $this->log("Aluno da Tutory ignorado: e-mail ausente ou inválido (nome={$nomeTutory}, id=".($tutoryId !== '' ? $tutoryId : '—').').');

            return 'pulado';
        }

        $aluno = $this->localizar($origem);
        if ($aluno === null) {
            return $this->cadastrar($origem);
        }

        return $this->editar($aluno, $origem);
    }

    /**
     * @param  array{id: string, nome: string, email: string, tem_telefone?: bool, telefone?: string|null}  $origem
     */
    private function localizar(array $origem): ?Aluno
    {
        $porId = $origem['id'] !== '' ? Aluno::encontrarPorTutoryId($origem['id']) : null;
        if ($porId !== null) {
            return $porId;
        }
        $porEmail = Aluno::encontrarPorEmail($origem['email']);
        if ($porEmail !== null) {
            return $porEmail;
        }

        $porNome = Aluno::encontrarPorNome($origem['nome']);
        if ($porNome === null) {
            return null;
        }

        // Nome é único no admin: o mesmo aluno pode reaparecer na Tutory com outro id.
        return $porNome;
    }

    /**
     * @param  array{id: string, nome: string, email: string, tem_telefone: bool, telefone: string|null}  $origem
     */
    private function cadastrar(array $origem): string
    {
        $donoNome = $this->outroComNome($origem['nome'], null);
        if ($donoNome !== null) {
            $this->logDuplicidade(
                'cadastrar',
                $origem['nome'],
                $origem['id'],
                $donoNome
            );

            return 'pulado';
        }
        $donoEmail = $this->outroComEmail($origem['email'], null);
        if ($donoEmail !== null) {
            $this->logDuplicidade(
                'cadastrar',
                $origem['nome'],
                $origem['id'],
                $donoEmail,
                'e-mail'
            );

            return 'pulado';
        }

        $dados = [
            'tutory_id' => $origem['id'] !== '' ? $origem['id'] : null,
            'nome' => $origem['nome'],
            'email' => $origem['email'],
            'recebe_email' => true,
            'ativo' => true,
        ];
        if ($origem['tem_telefone']) {
            $dados['telefone'] = $origem['telefone'];
        }

        try {
            Aluno::create($dados);
        } catch (Throwable $exc) {
            $this->log("Falha ao cadastrar {$origem['nome']}: ".$exc->getMessage());

            return 'pulado';
        }

        $telefone = $origem['tem_telefone'] ? ($origem['telefone'] ?? '—') : 'não consultado';
        $this->log("Cadastrado: {$origem['nome']} <{$origem['email']}> (recebe_email=true, telefone={$telefone})");

        return 'criado';
    }

    /**
     * @param  array{id: string, nome: string, email: string, tem_telefone: bool, telefone: string|null}  $origem
     */
    private function editar(Aluno $aluno, array $origem): string
    {
        $mudou = false;

        if ($origem['id'] !== '' && (string) $aluno->tutory_id !== $origem['id']) {
            $antes = filled($aluno->tutory_id) ? $aluno->tutory_id : '—';
            $this->log("tutory_id divergente: plataforma \"{$antes}\" → Tutory \"{$origem['id']}\" (aluno id {$aluno->id}, nome {$aluno->nome}).");
            $aluno->tutory_id = $origem['id'];
            $mudou = true;
        }

        if (Aluno::normalizarNome($aluno->nome) !== Aluno::normalizarNome($origem['nome'])) {
            $donoNome = $this->outroComNome($origem['nome'], $aluno->id);
            if ($donoNome !== null) {
                $this->logDuplicidade('editar', $origem['nome'], $origem['id'], $donoNome);
            } else {
                $this->log("Nome divergente: plataforma \"{$aluno->nome}\" → Tutory \"{$origem['nome']}\" (aluno id {$aluno->id}).");
                $aluno->nome = $origem['nome'];
                $mudou = true;
            }
        }

        if (mb_strtolower($aluno->email) !== mb_strtolower($origem['email'])) {
            $donoEmail = $this->outroComEmail($origem['email'], $aluno->id);
            if ($donoEmail !== null) {
                $this->logDuplicidade('editar', $origem['nome'], $origem['id'], $donoEmail, 'e-mail');
            } else {
                $this->log("E-mail divergente: plataforma {$aluno->email} → Tutory {$origem['email']} (aluno id {$aluno->id}).");
                $aluno->email = $origem['email'];
                $mudou = true;
            }
        }

        if ($origem['tem_telefone'] && $aluno->telefone !== $origem['telefone']) {
            $antes = filled($aluno->telefone) ? $aluno->telefone : '—';
            $depois = $origem['telefone'] ?? '—';
            $this->log("Telefone divergente: plataforma \"{$antes}\" → Tutory \"{$depois}\" (aluno id {$aluno->id}).");
            $aluno->telefone = $origem['telefone'];
            $mudou = true;
        }

        if (! $aluno->recebe_email) {
            $aluno->recebe_email = true;
            $mudou = true;
        }

        if (! $aluno->ativo) {
            $aluno->ativo = true;
            $mudou = true;
        }

        if (! $mudou) {
            return 'inalterado';
        }

        try {
            $aluno->save();
        } catch (Throwable $exc) {
            $this->log("Falha ao editar {$origem['nome']}: ".$exc->getMessage());

            return 'pulado';
        }

        $this->log("Atualizado: {$aluno->nome} <{$aluno->email}> (recebe_email=true)");

        return 'atualizado';
    }

    /**
     * Aluno que já está no portal e não está ativo na Tutory tem o status atualizado.
     * Quem não existe no portal não é cadastrado.
     *
     * @param  list<array{id?: string, nome?: string, email?: string}>  $alunosTutory
     */
    public function atualizarInativos(array $alunosTutory): int
    {
        $atualizados = 0;

        foreach ($alunosTutory as $origem) {
            $dados = [
                'id' => trim((string) ($origem['id'] ?? '')),
                'nome' => trim((string) ($origem['nome'] ?? '')),
                'email' => mb_strtolower(trim((string) ($origem['email'] ?? ''))),
            ];
            if ($dados['nome'] === '' || $dados['email'] === '') {
                continue;
            }
            if ($this->isTeacher($dados['nome'], $dados['email'])) {
                continue;
            }
            $aluno = $this->localizar($dados);
            if ($aluno === null || $aluno->isTeacher() || ! $aluno->ativo) {
                continue;
            }
            $aluno->ativo = false;
            try {
                $aluno->save();
            } catch (Throwable $exc) {
                $this->log("Falha ao inativar {$dados['nome']}: ".$exc->getMessage());

                continue;
            }
            $atualizados++;
            $this->log("Status atualizado: {$aluno->nome} ficou inativo (não está ativo na Tutory).");
        }

        return $atualizados;
    }

    private function isTeacher(string $nome, string $email): bool
    {
        return (new Aluno(['nome' => $nome, 'email' => $email]))->isTeacher();
    }

    private function eAlunoTeste(string $nome): bool
    {
        return Aluno::normalizarNome($nome) === Aluno::normalizarNome(self::NOME_ALUNO_IGNORADO);
    }

    private function outroComNome(string $nome, int|string|null $excetoId): ?Aluno
    {
        $encontrado = Aluno::encontrarPorNome($nome);
        if ($encontrado === null) {
            return null;
        }
        if ($excetoId !== null && (int) $encontrado->id === (int) $excetoId) {
            return null;
        }

        return $encontrado;
    }

    private function outroComEmail(string $email, int|string|null $excetoId): ?Aluno
    {
        $encontrado = Aluno::encontrarPorEmail($email);
        if ($encontrado === null) {
            return null;
        }
        if ($excetoId !== null && (int) $encontrado->id === (int) $excetoId) {
            return null;
        }

        return $encontrado;
    }

    private function logDuplicidade(string $acao, string $nome, string $tutoryId, Aluno $existente, string $campo = 'nome'): void
    {
        $idTutory = $tutoryId !== '' ? $tutoryId : '—';
        $mensagem = "Nome duplicado ao {$acao} \"{$nome}\" (Tutory id {$idTutory}). "
            ."Já existe aluno id {$existente->id} ({$existente->nome} <{$existente->email}>).";
        if ($campo === 'e-mail') {
            $mensagem = "E-mail duplicado ao {$acao} \"{$nome}\" (Tutory id {$idTutory}). "
                ."Já existe aluno id {$existente->id} ({$existente->nome} <{$existente->email}>).";
        }
        $this->log($mensagem);
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
        try {
            Log::info('[tutory:sincronizar-alunos] '.$message);
        } catch (Throwable) {
            // Logger do comando já recebeu a mensagem.
        }
    }
}
