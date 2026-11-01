<?php

use App\Http\Controllers\AlunoController;
use App\Http\Controllers\AnonymousVisitAdminController;
use App\Http\Controllers\AnonymousVisitController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BoasVindasAdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesempenhoAdminController;
use App\Http\Controllers\InscricaoAdminController;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\LeadAdminController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MailTestController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\RelatorioPdfAdminController;
use App\Http\Controllers\RelatorioPdfContingenciaController;
use App\Http\Controllers\TurmaController;
use App\Models\Material;
use App\Models\Turma;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/', function () {
    $materials = Material::with('turmas')->orderBy('created_at', 'desc')->get();
    $turmas = Turma::query()->naMentoria()->ordenado()->get();

    return view('landing', compact('materials', 'turmas'));
});

Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
Route::post('/inscricoes', [InscricaoController::class, 'store'])->name('inscricoes.store');

// Rota de teste de e-mail (remover em produção)
Route::get('/teste-email', MailTestController::class)->name('mail.test');
Route::post('/anonymous-visits', [AnonymousVisitController::class, 'store'])->name('anonymous-visits.store');
Route::post('/anonymous-visits/touch', [AnonymousVisitController::class, 'touch'])->name('anonymous-visits.touch');
Route::post('/anonymous-visits/exit', [AnonymousVisitController::class, 'update'])->name('anonymous-visits.update');

// Admin routes - protected by auth middleware
Route::middleware(['auth'])->prefix('admin')->group(function () {
    // Dashboard de acompanhamento
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::post('/dashboard/alunos/{aluno}/contatos', [DashboardController::class, 'storeContato'])->name('admin.dashboard.contatos.store');
    Route::get('/dashboard/alunos/{aluno}/whatsapp', [DashboardController::class, 'whatsapp'])->name('admin.dashboard.whatsapp');
    Route::get('/dashboard/alunos/{aluno}/proximo-contato', [DashboardController::class, 'agendarProximo'])->name('admin.dashboard.contatos.agendar');
    Route::post('/dashboard/alunos/{aluno}/proximo-contato', [DashboardController::class, 'storeAgendarProximo'])->name('admin.dashboard.contatos.agendar.store');
    Route::post('/dashboard/alunos/{aluno}/agenda', [DashboardController::class, 'storeAgenda'])->name('admin.dashboard.agenda.store');

    // Material routes
    Route::get('/materiais', [MaterialController::class, 'index'])->name('materiais.index');
    Route::get('/materiais/create', [MaterialController::class, 'create'])->name('materiais.create');
    Route::post('/materiais', [MaterialController::class, 'store'])->name('materiais.store');
    Route::get('/materiais/{material}/edit', [MaterialController::class, 'edit'])->name('materiais.edit');
    Route::put('/materiais/{material}', [MaterialController::class, 'update'])->name('materiais.update');
    Route::delete('/materiais/{material}', [MaterialController::class, 'destroy'])->name('materiais.destroy');
    Route::get('/materiais/{material}/edit', [MaterialController::class, 'edit'])->name('materiais.edit');
    Route::put('/materiais/{material}', [MaterialController::class, 'update'])->name('materiais.update');
    Route::delete('/materiais/{material}', [MaterialController::class, 'destroy'])->name('materiais.destroy');

    // Turmas routes
    Route::get('/turmas', [TurmaController::class, 'index'])->name('turmas.index');
    Route::get('/turmas/create', [TurmaController::class, 'create'])->name('turmas.create');
    Route::post('/turmas', [TurmaController::class, 'store'])->name('turmas.store');
    Route::get('/turmas/{turma}/edit', [TurmaController::class, 'edit'])->name('turmas.edit');
    Route::put('/turmas/{turma}', [TurmaController::class, 'update'])->name('turmas.update');
    Route::delete('/turmas/{turma}', [TurmaController::class, 'destroy'])->name('turmas.destroy');

    // Alunos routes
    Route::get('/alunos', [AlunoController::class, 'index'])->name('alunos.index');
    Route::post('/alunos/sincronizar', [AlunoController::class, 'sincronizar'])->name('alunos.sincronizar');
    Route::get('/alunos/export/csv', [AlunoController::class, 'export'])->name('alunos.export');
    Route::get('/alunos/create', [AlunoController::class, 'create'])->name('alunos.create');
    Route::post('/alunos', [AlunoController::class, 'store'])->name('alunos.store');
    Route::get('/alunos/{aluno}/edit', [AlunoController::class, 'edit'])->name('alunos.edit');
    Route::put('/alunos/{aluno}', [AlunoController::class, 'update'])->name('alunos.update');
    Route::delete('/alunos/{aluno}', [AlunoController::class, 'destroy'])->name('alunos.destroy');

    // Gestão de desempenho (eixos e faixas do documento de parâmetros)
    Route::get('/desempenho', [DesempenhoAdminController::class, 'index'])->name('desempenho.index');
    Route::get('/desempenho/{desempenho}/edit', [DesempenhoAdminController::class, 'edit'])->name('desempenho.edit');
    Route::put('/desempenho/{desempenho}', [DesempenhoAdminController::class, 'update'])->name('desempenho.update');

    Route::get('/boas-vindas', [BoasVindasAdminController::class, 'edit'])->name('boas-vindas.edit');
    Route::put('/boas-vindas', [BoasVindasAdminController::class, 'update'])->name('boas-vindas.update');

    // Fonte e preview do PDF dos relatórios
    Route::get('/relatorios-pdf', [RelatorioPdfAdminController::class, 'index'])->name('relatorios-pdf.index');
    Route::put('/relatorios-pdf', [RelatorioPdfAdminController::class, 'update'])->name('relatorios-pdf.update');
    Route::get('/relatorios-pdf/preview', [RelatorioPdfAdminController::class, 'preview'])->name('relatorios-pdf.preview');

    // Contingência: PDF de um aluno em períodos já liberados (download e reenvio de e-mail)
    Route::get('/relatorios-pdf-contingencia', [RelatorioPdfContingenciaController::class, 'index'])->name('relatorios-pdf-contingencia.index');
    Route::put('/relatorios-pdf-contingencia', [RelatorioPdfContingenciaController::class, 'update'])->name('relatorios-pdf-contingencia.update');
    Route::get('/relatorios-pdf-contingencia/progresso', [RelatorioPdfContingenciaController::class, 'progresso'])->name('relatorios-pdf-contingencia.progresso');
    Route::post('/relatorios-pdf-contingencia', [RelatorioPdfContingenciaController::class, 'gerar'])->name('relatorios-pdf-contingencia.gerar');

    // Leads routes
    Route::get('/leads', [LeadAdminController::class, 'index'])->name('leads.index');
    Route::delete('/leads/{lead}', [LeadAdminController::class, 'destroy'])->name('leads.destroy');
    Route::get('/leads/export/csv', [LeadAdminController::class, 'export'])->name('leads.export');

    // Anonymous visit routes
    Route::get('/anonymous-visits', [AnonymousVisitAdminController::class, 'index'])->name('anonymous-visits.index');

    // Inscricoes routes
    Route::get('/inscricoes', [InscricaoAdminController::class, 'index'])->name('inscricoes.index');
    Route::delete('/inscricoes/{inscricao}', [InscricaoAdminController::class, 'destroy'])->name('inscricoes.destroy');
    Route::get('/inscricoes/export/csv', [InscricaoAdminController::class, 'export'])->name('inscricoes.export');
});

// Public download route (forces download)
Route::get('/materiais/{material}/download', [MaterialController::class, 'download'])->name('materiais.download');
