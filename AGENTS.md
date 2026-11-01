# AGENTS.md

Leia este arquivo no início de cada tarefa. Ele descreve o que este repositório é, como está organizado e quais padrões seguir. O `README.md` da raiz ainda é o texto padrão do Laravel e não documenta o produto.

Documentação de domínio, quando precisar de detalhe:

- `docs/tutory-relatorios.md` — download, PDF consolidado, e-mail e agendamento
- `docs/desempenho-readme.md` — placeholders dos textos de desempenho

## Escopo

**Missão Nomeação** é o backend da mentoria para concursos. Faz três coisas:

1. **Site e captação.** Landing em Blade (`/`), materiais para download, leads e inscrições. A API pública `GET /api/turmas` e `GET /api/turmas/{slug}` alimenta o site (origens em `CORS_ALLOWED_ORIGINS`).
2. **Admin da mentora** (`/admin`, sessão Laravel). Turmas, materiais, alunos, leads, inscrições, visitas anônimas, parâmetros de desempenho, fonte do PDF e um dashboard de acompanhamento (intervir, marcar presença, parabenizar, ok, restabelecer contato).
3. **Relatórios do Coach (Tutory).** Jobs Artisan entram no admin da Tutory, baixam os HTMLs oficiais dos alunos ativos, montam **um** PDF consolidado, classificam o desempenho, gravam as faixas no aluno e enviam **um** e-mail com **um** anexo. O envio exige aluno ativo na Tutory, nome igual no admin, `recebe_email=true` e e-mail válido.

Produção roda na Hostinger, em `domains/missaonomeacao.com.br/public_html/server`, servida em `https://missaonomeacao.com.br/server`. O front controller é `public/index.php`, mas as URLs públicas não levam `/public` (`App\Http\HostingerSubdirectory` e `AppServiceProvider::urlSemSufixoPublic`).

O fuso de negócio é **America/Sao_Paulo** (UTC−3 o ano inteiro). Horário de job, data de período e “hoje” do dashboard usam esse fuso, não o relógio UTC do servidor nem do GitHub Actions.

## Stack

- PHP 8.2, Laravel 12, Eloquent, Blade, sessão em banco
- Banco padrão e de produção: **SQLite** (`database/database.sqlite`). PHPUnit usa SQLite `:memory:`
- Tailwind via CDN nas páginas Blade (landing e admin). Vite + Tailwind 4 existem em `resources/css/app.css`, mas as telas em uso não dependem do build
- PDF em produção: **PHP/Dompdf** (`dompdf/dompdf`, `setasign/fpdi`). Gráficos do relatório quinzenal em GD (`GraficosRelatorioQuinzenal`); QuickChart só nos PDFs individuais
- Puppeteer (`scripts/tutory-*.mjs`) é opcional e fica desligado. O servidor compartilhado **não tem Node nem npm**
- E-mail SMTP. Todo envio (lead, inscrição, relatório) sai com CCO `MAIL_BCC_ADDRESS` (padrão `nayara@missaonomeacao.com.br`, em `config/mail.php`)
- Auth do admin: `Auth::attempt` em `LoginController`. Não há policies nem gates. Rotas de `/admin` usam o middleware `auth`

`docker-compose.yml` sobe Nginx + PHP + MySQL para ambiente local. Não trate esse MySQL como o banco de produção.

## Estrutura

```
app/
  Console/Commands/     # Artisan fino: valida opção e delega ao service
  Enums/                # enums de string do acompanhamento
  Http/Controllers/     # admin, landing e Api/TurmaPublicController
  Http/Requests/        # hoje só TurmaRequest
  Http/Resources/       # hoje só TurmaPublicResource
  Mail/                 # BaseEmail + EmailLead, EmailInscricao, EmailRelatorioCoach
  Models/
  Services/
    Tutory/             # login, download, PDF, agenda, sync de alunos
    Desempenho/         # AvaliadorDesempenho (faixas e textos do e-mail)
    Acompanhamento/     # painel do dashboard
routes/web.php          # landing, login e /admin
routes/api.php          # turmas públicas (prefixo /api)
routes/console.php      # schedule de todo minuto
database/migrations/    # schema; nomes de tabela em português
database/seeders/       # admin, turmas e parâmetros de desempenho
resources/views/        # Blade: landing, admin/, emails/, components/
resources/relatorios/   # capa.pdf e capa-final.pdf institucionais
scripts/                # cron da Hostinger; Puppeteer só para debug
docs/                   # comportamento de relatório e desempenho
tests/Feature/          # HTTP, admin, comandos
tests/Unit/             # services, agenda, PDF, desempenho
.github/workflows/
  deploy.yml            # push em master → rsync na Hostinger
  tutory-relatorios.yml # reforço UTC dos horários da Tutory
```

Pastas legadas que não são o caminho ativo: `resources/views/admin/materials/` (o controller usa `admin/materiais/`). Não crie um paralelo em inglês.

## Domínios

### Turmas, leads e inscrições

`Turma` concentra o catálogo do site: grupo de exibição, estágio, órgão, cargo, momento do concurso e ação principal (checkout, WhatsApp ou lista). Constantes e rótulos ficam na model. Scopes: `publicasNoSite`, `naMentoria`, `nasTurmasAbertas`, `ordenado`.

Leads e inscrições são formulários públicos. O envio de e-mail passa por `App\Http\Util\MailHelper`, que monta o Mailable e aplica o CCO.

### Alunos

Cadastro local em `alunos`, casado com a Tutory. A busca usa, nesta ordem, `tutory_id`, e-mail e nome normalizado (`Aluno::normalizarNome`: trim, espaços colapsados, minúsculas). O nome é único.

A Tutory sempre devolve um cadastro **Aluno teste**. O sync ignora esse nome e não grava a linha. **Nayara Oliveira** (`nayara@missaonomeacao.com.br`) é a mentora: `Aluno::isTeacher()` a tira do dashboard.

Colunas de faixa do último período (não renomeie; o admin e o e-mail já leem estes nomes):

- `last_performance` / `last_performance_codigo` — constância
- `last_question_volume` / `last_question_volume_codigo`
- `last_accuracy_rate` / `last_accuracy_rate_codigo`
- `last_subjects`
- `prev_*` — período anterior, para a tendência do dashboard

`recebe_email` é uma das condições do e-mail do PDF quinzenal; a regra completa está em Relatórios Tutory. `ativo` espelha o status na Tutory. `telefone` guarda o contato no padrão `55` + DDD + número, só dígitos (DDD `61` e telefone `9912-38860` viram `5561991238860`). O sync dos ativos abre Ações → Cadastro (`/alunos/index?aid={id}`) e grava o valor da Tutory por cima do local. Cadastro sem DDD ou sem número deixa o campo vazio. Falha ao abrir o cadastro não apaga o telefone já salvo. Alunos desativados não passam por essa etapa.

### Desempenho

Eixos em `eixos_desempenho`, faixas editáveis em `/admin/desempenho`. Códigos: `constancia`, `volume_questoes`, `percentual_acertos`, `assunto`. `AvaliadorDesempenho` lê as métricas extraídas dos HTMLs e devolve os blocos de texto do e-mail. Placeholders: `{NOME}`, `{X}`, `{Y}`, `{Z}`, `{TOTAL_QUESTOES}`, `{PERCENTUAL_ACERTOS}`, `{LISTA_ASSUNTOS}`, `{ASSUNTO}`, `{PERCENTUAL}`.

O percentual geral só entra com 100 questões ou mais. Assuntos com rendimento baixo viram um bloco com bullets. O seed é `ParametrosDesempenhoSeeder`.

### Acompanhamento

O dashboard (`/admin/dashboard`) não recalcula o PDF. Ele lê as faixas já gravadas no aluno e classifica a ação com os enums de `app/Enums` (`AcaoAcompanhamento`: intervir > marcar presença > parabenizar > ok). Aluno inativo não disputa essa ordem: o montador troca a ação para restabelecer contato. A regra mora em `App\Services\Acompanhamento`. Contatos e o próximo contato ficam em `contatos_aluno` e nas colunas `ultimo_contato_em` / `proximo_contato_em` do aluno.

### Relatórios Tutory

Comando de entrada: `php artisan tutory:baixar-relatorios`.

- `--periodo=1` — dias 01–15 do mês corrente
- `--periodo=2` — dia 16 até o último dia do mês (nos dias 1–15, o mês anterior)
- `--teste` — só o aluno Mychel; não grava a trava de envio
- `--se-pendente` — não reenvia se aquele período do mês já concluiu

O service é `App\Services\Tutory\CoachReportDownloader`. Fluxo: login na Tutory, lista de alunos ativos, cinco HTMLs oficiais por aluno (`desempenho`, `aluno`, `horas-liquidas`, `questoes`, `progresso`), um PDF consolidado, avaliação, e-mail, e **apagar os PDFs** da pasta de download. Logs `log_download_*.txt` permanecem.

O e-mail com o PDF sai só quando as três condições valem juntas:

1. O aluno está ativo na Tutory (`/alunos/consulta?status=ativos`, com `data-id`). É essa lista que gera o consolidado. Aluno desativado não entra, então não há PDF novo.
2. O cadastro em `alunos` tem o mesmo nome do arquivo `relatorio_consolidado_*`.
3. `recebe_email` está marcado e o e-mail é válido. Sem o checkbox, as faixas ainda são gravadas e o e-mail não sai.

O sync das 06:00 volta a marcar `recebe_email` em quem continua ativo na Tutory. Desmarcado no admin, o envio segura só até essa sincronização. **Aluno teste** e **Nayara Oliveira** não estão na tabela local, então não recebem o e-mail. `--teste` limita geração e envio a quem tem "Mychel" no nome. O agendado manda uma vez por quinzena (`--se-pendente`): período 1 no dia 16, período 2 no dia 1.

O PDF final não é a junção dos cinco PDFs oficiais. `montarDadosRelatorio` extrai os dados sem alterar os números da Tutory (`DadosRelatorioQuinzenal`) e `RelatorioQuinzenalPdf` monta o layout aprovado: capa com nome, curso e período, resumo, ritmo, questões, assuntos, revisões, histórico por disciplina e última folha. Identidade: azul `#0B1F3F`, dourado `#F2B705`, Montserrat + Nunito Sans. Fundo e logo da capa em `resources/relatorios/`; a última folha é `capa-final.pdf`. Detalhes em `docs/tutory-relatorios.md`.

Motor padrão: `TUTORY_PDF_ENGINE=dompdf`. Não troque o padrão para Puppeteer e não acrescente `npm install` ao deploy.

Trava de “já enviei” e heartbeat ficam na tabela `configuracoes`, via `Configuracao::valor()` e `Configuracao::definir()`. Chaves no formato `tutory.job.*` e `tutory.scheduler.heartbeat`.

### Agenda

Quem decide se o minuto é de um job é `TutoryAgendaDoDia` (America/Sao_Paulo). Cada horário vale no minuto marcado e nos **4 minutos seguintes**. Um tick às 11:05 não dispara o job das 06:00.

| Job                       | Comando                                              | Quando                                                                    |
| ------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------- |
| Sync                      | `tutory:sincronizar-alunos`                          | todo dia, 06:00                                                           |
| Período 1                 | `tutory:baixar-relatorios --periodo=1 --se-pendente` | dia 16, 10:30; retentativa de hora em hora, 11:00–22:00, nos dias 16 e 17 |
| Período 2                 | `tutory:baixar-relatorios --periodo=2 --se-pendente` | dia 1, 10:30; retentativa 11:00–22:00 nos dias 1 e 2                      |
| Liberar períodos no admin | `tutory:liberar-periodos-pdf`                        | dias 1 e 16, 00:05                                                        |

O Laravel não dispara sozinho. O relógio é um cron de todo minuto na Hostinger (`scripts/tutory-scheduler.sh`), que chama `tutory:executar-agendados`. O schedule em `routes/console.php` também é de todo minuto. A Action `tutory-relatorios.yml` repete os mesmos horários em UTC. Uma visita ao site chama `TutorySchedulerKick` no `terminating`, só dentro dessa janela de 4 minutos, e sem gerar PDF.

A trava do sync (`tutory.job.sincronizar-alunos.YYYY-MM-DD`) vale para o agendado. Rodar o comando à mão não é bloqueado por ela.

## Padrões de código

- **Idioma do domínio em português:** classes, rotas, views, comentários e mensagens ao usuário (`Aluno`, `TurmaController`, `tutory:baixar-relatorios`, “Aluno criado com sucesso.”). Não traduza o que já está em português e não crie o equivalente em inglês ao lado.
- **Inglês permanece** onde já é contrato: colunas `last_*` / `prev_*`, `tutory_id`, facades do Laravel, nomes de teste em snake_case (`test_admin_nao_cria_aluno_com_nome_duplicado`).
- **Controller fino no fluxo novo, explícito no CRUD.** CRUD de admin valida com `$request->validate()` e redireciona com `->with('success', ...)`. Form Request só quando o payload é o de turma (`TurmaRequest`). Dashboard e commands recebem a regra pronta de um service; não reimplemente a classificação na view.
- **Service por pasta de domínio.** Tutory não importa acompanhamento. Desempenho não conhece HTTP. Command Artisan só interpreta opção, loga e devolve `SUCCESS` / `FAILURE`.
- **Enums backed string** em `app/Enums`, com `rotulo()` e, quando houver ordem, `prioridade()`. Novo estado de acompanhamento entra no enum e no classificador, não como string solta na Blade.
- **Model guarda invariante de leitura:** `$fillable`, casts, scopes e buscas (`encontrarPorTutoryId`, `encontrarPorEmail`, `encontrarPorNome`). Regra que cruza vários modelos vai para `app/Services`.
- **Casts:** arquivos novos usam `casts(): array`. Ao editar um arquivo que ainda tem `protected $casts`, mantenha o estilo desse arquivo.
- **Views Blade** estendem `layouts/admin` no admin e usam `lang="pt-BR"`. Tailwind dessas telas é o CDN do próprio layout, com `primary` / `primary-light`. Mudar `resources/css/app.css` não altera a landing nem o admin.
- **E-mail novo** estende `BaseEmail` e é enviado por `MailHelper`, para herdar o CCO. Não chame `Mail::to()` direto.
- **Testes PHPUnit** (não Pest). Feature com `RefreshDatabase` e `actingAs(User::factory())`. Unit para agenda, PDF, desempenho e parsing, sem HTTP. Asserção pelo comportamento visível: redirect, erro de validação, texto na tela, linha no banco. Rode `php artisan test` ou `composer test`.
- **Estilo PHP:** Laravel Pint no preset padrão (`vendor/bin/pint`). Sem `declare(strict_types=1)`. Tipar parâmetro e retorno quando o arquivo ao redor já faz isso.

## Deploy e dados que não podem ir para o git

O deploy é a Action `deploy.yml`, no push da branch `master`, por rsync para a Hostinger. `develop` é a branch de integração. A Action roda `composer install --no-dev`, `php artisan migrate --force` e o cron.

O rsync usa `--delete` e **preserva** o que existe só no servidor: `.env`, `database/*.sqlite*`, `public/storage/` (capas de turma) e `storage/app/public/`. Não apague esses excludes. Não commite `.env`, SQLite, PDF gerado nem HTML de debug da Tutory (`adminUser.token` vaza).

`PASTA_DOWNLOAD` vazio cai em `public/pdfs`, que está no `.gitignore`. Prefira um diretório fora de `public/` quando for configurar caminho novo.

Credenciais da Tutory são `LOGIN_USER` e `LOGIN_PASSWORD`. Não hardcode senha em código novo.

## Ao alterar o código

- Mudou horário, período, trava ou conteúdo do PDF: atualize `docs/tutory-relatorios.md` e o teste em `tests/Unit/TutoryAgendaDoDiaTest.php` ou o teste do PDF correspondente.
- Mudou placeholder ou faixa padrão: atualize `docs/desempenho-readme.md` e o seeder só se o padrão de instalação também mudar. Faixa já editada no admin de produção não é reescrita por seeder.
- Nova coluna: migration com data, `$fillable` e cast. Produção aplica com `migrate --force` no deploy; não dependa de `migrate:fresh`.
- Não adicione Node, Puppeteer ou Chrome ao caminho de produção.
- Não calcule “está na hora?” com `now()` em UTC. Use `America/Sao_Paulo`, como `TutoryAgendaDoDia`.
- Evite utilizar sub agentes que façam uso de ferramentas visuais para validar as mudanças realizadas

## Cursor Cloud specific instructions

O Cloud Agent não sobe o `docker-compose.yml`. O banco do produto é SQLite (`database/database.sqlite`).

O `install` do ambiente instala PHP 8.3 (cli, mbstring, xml, curl, zip, sqlite, bcmath, gd, intl), Composer e `poppler-utils` (`pdftotext`, exigido pelos testes de PDF). Em seguida roda `composer install --no-interaction --prefer-dist`, copia `.env.example` para `.env` se faltar, gera `APP_KEY`, cria o SQLite e executa `php artisan migrate --force` e `php artisan db:seed --force`. No `.env` gerado, `APP_URL` fica `http://127.0.0.1:8000` e `MAIL_MAILER` fica `log` (não há SMTP local).

O `start` cria `database/database.sqlite` se faltar, reaplica `php artisan migrate --force` e `php artisan db:seed --force` (para pegar migration de branch), e sobe `php artisan serve --host=0.0.0.0 --port=8000`. Saúde: `GET /up`. Se a porta já responde, o script termina sem abrir outro processo. Os seeders usam `updateOrCreate`, então repetir o `start` não duplica turmas nem usuários.

- Testes: `php artisan test` ou `composer test`. Estilo: `vendor/bin/pint --test`.
- Landing, admin e `GET /api/turmas` usam Tailwind pelo CDN. Não rode `npm install`: o `package.json` instala Puppeteer, que permanece desligado.
- Login local sai de `database/seeders/AdminUserSeeder.php`.
- Relatórios da Tutory só rodam com `LOGIN_USER` e `LOGIN_PASSWORD`. O admin e a API pública não dependem dessas variáveis.
