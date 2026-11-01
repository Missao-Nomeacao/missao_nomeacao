# Relatórios do Coach (Tutory)

CLI PHP que baixa os relatórios do Coach dos alunos **ativos** no Tutory, **consolida as seções pedidas em um único PDF** e envia por e-mail aos alunos cadastrados no admin (`recebe_email=true`).

No Hostinger compartilhado **não precisa de Node nem de npm**. O PDF é montado com PHP/Dompdf a partir dos HTMLs oficiais. Puppeteer é opcional e fica desligado por padrão.

## O que entra no PDF único

Não é a junção dos cinco PDFs oficiais. O compositor extrai os dados da Tutory **sem alterá-los** (`CoachReportDownloader::montarDadosRelatorio` → `DadosRelatorioQuinzenal`) e o `RelatorioQuinzenalPdf` monta o layout aprovado pela mentora. A quantidade de páginas é consequência do conteúdo; seção sem dado é omitida. Não há cabeçalho nem número de página nas páginas internas.

Ordem fixa do PDF final:

1. **Capa** — gerada pelo Dompdf para cada aluno: fundo `resources/relatorios/capa-fundo.jpg`, logo `logo-amarelo.png`, "Relatório de Desempenho", nome do aluno, curso e período por extenso ("16 a 30 de setembro"). Se a capa falhar, entra a fixa `capa.pdf`.
2. **Resumo da quinzena** — "Como foram os seus N dias": cards *Média por dia* (Painel de Insights, em destaque), *Plano cumprido* (soma do estudado ÷ soma do planejado, de `#chart_horas_diarias`), *Questões* e *Acertos* (panorama `.main-numbers`); faixa com os cards de desempenho geral (`.metric-card`), com barra quando o card é de progresso em %; matéria mais e menos estudada, com as horas do período tiradas do histórico.
3. **Ritmo de estudos** — "Horas por dia" (barra do estudado; traço dourado = meta do dia; barra clara = abaixo da meta) de `#chart_horas_diarias` e "Horas brutas × horas líquidas" de `#chart_line_comparativo`.
4. **Questões** — só os dias com questões (`#chart_questoes_dia`, séries "questões corretas" e "questões erradas"), barra de acertos × erros e o % do dia.
5. **Performance por assunto** — `#tabela_questoes`, com medidor colorido: verde a partir de 80%, âmbar de 70% a 79%, vermelho abaixo de 70%.
6. **Revisões no período** — `#tabela_revisoes`; sem linhas, vira o aviso "Nenhuma revisão registrada nesta quinzena".
7. **Histórico completo** — `#tabela_horas_liquidas` agrupado por disciplina (total e barra proporcional), com modalidade em etiqueta colorida.
8. **Última folha** — PDF fixo `resources/relatorios/capa-final.pdf`.

Quando todas as disciplinas têm o mesmo prefixo do curso ("PMSC - Português"), o prefixo sai na exibição, porque o curso já está na capa. Nome de disciplina que vem todo em maiúsculas ("LEGISLAÇÃO DE TRÂNSITO") é exibido em caixa de título ("Legislação de Trânsito"). O "Curso:" que a Tutory põe antes do curso sai da capa. A *Média por dia* vem do insight "Sua média de tempo por dia foi de …".

Os gráficos são desenhados com GD (`GraficosRelatorioQuinzenal`), sem QuickChart. A Tutory manda horas decimais; se a série vier em minutos ou segundos, ela é convertida para horas.

| Origem | Fora do consolidado |
|--------|---------------------|
| **Desempenho (NOVO)** `desempenho` | Ranking, panorama, gráficos Horas de estudo / Performance por Área, progresso mensal, modalidades, progresso por disciplina, insights desse relatório, rodapé |
| **Estudos** `aluno` | Histórico de Metas (`#tabela_estudos`), panorama, gráficos de questões, performance por assunto (duplicata) |
| **Horas Líquidas** `horas-liquidas` | Pizza por disciplina, progresso por disciplina |
| **Questões** `questoes` | Bolha, top melhores/piores, evolução por matéria |
| **Progresso do plano** `progresso` | Progresso principal, panorama, modalidades, desempenho de questões |

Identidade do relatório quinzenal: azul `#0B1F3F`, dourado `#F2B705`, Montserrat nos títulos e números, Nunito Sans no texto (`resources/fonts/montserrat` e `resources/fonts/nunito-sans`). Sem marca d'água diagonal. O `RelatorioConsolidadoLayout` continua servindo os PDFs individuais e o motor Puppeteer opcional.

## Fluxo

1. `POST /intent/login` → sessão + Bearer (`adminUser.token`)
2. `GET /alunos/consulta?status=ativos` → `data-id` de cada aluno
3. Para **cada modelo** em `RELATORIOS` (mesmo token de geração):
   1. `POST /intent/cadastrar-relatorio-coach` (`alunos[]`, `dt_ini`, `dt_fim`, `agrupamento=dia`)
   2. `GET /documentos/relatorios/{model}?key=...` (HTML oficial; as páginas do Tutory **não são alteradas**)
4. Monta **um** PDF com **PHP/Dompdf** e gráficos em GD (padrão no Hostinger; Puppeteer só se `TUTORY_PDF_ENGINE=puppeteer`)
5. Reprocessa falhas por aluno (até 3 tentativas)
6. Lista alunos do **admin** (`alunos`) **ativos**. Inativo e cadastro local que **não estava** na lista de ativos da Tutory nesta execução não entram como “PDF não localizado”: o e-mail simplesmente não é tentado. Quem tem **menos de 15 dias** de cadastro é aluno novato: o PDF não é gerado, as faixas não são gravadas e, se `recebe_email=true`, sai só o e-mail de boas-vindas (texto único em `/admin/boas-vindas`, placeholder `{NOME}`). Os demais: localiza o PDF `relatorio_consolidado_*` → avalia o desempenho e grava as faixas no aluno (`last_performance` = constancy, `last_question_volume`, `last_accuracy_rate`, `last_subjects`) → envia **um único e-mail com 1 anexo** se `recebe_email=true` → **apaga todos os PDFs** da pasta de download (e os `.metricas.json` ao lado). Logs `log_download_*.txt` permanecem. No dashboard, sem contato neste ciclo a ação é Aluno novato (filtro do mesmo nome), à parte das faixas. Contato registrado deixa a ação em Ok e a frase do cadastro fica como observação na ficha; se a data agendada passar sem outro contato, a ação vira Marcar presença.
7. Ao fim da execução, gera um PDF resumido (alunos, PDFs, envios, alunos novatos e divergências de nome) e envia para `MAIL_RELATORIO_EXECUCAO_ADDRESS` (padrão `luispimenta.contato@gmail.com`), com o mesmo CCO dos outros e-mails. O novato entra na contagem de ativos, na tabela da execução (PDF não gerado, e-mail de boas-vindas) e na conclusão. Ele não conta como falha de geração nem como PDF não localizado. Horário e duração são os do log `log_download_*.txt` (a janela da geração). O arquivo fica em `storage/app/tutory-execucao/`, fora da pasta pública. Uma falha nesse envio não desfaz os relatórios já enviados aos alunos. `--se-pendente`, quando o período já foi enviado, encerra antes dessa etapa e não manda o resumo.

O renderer individual `scripts/tutory-render-pdf.mjs` continua no repositório para uso pontual. **Não é necessário em produção sem Node.**

## Hostinger / shared hosting (sem npm)

**Não rode `npm install`.** O comando `npm` não existe nesse servidor e **não é necessário**. O PDF consolidado sai com PHP/Dompdf por padrão (`TUTORY_PDF_ENGINE=dompdf`).

```bash
php artisan tutory:baixar-relatorios --periodo=1 --teste
```

Log esperado:

```
PDF com PHP/Dompdf — não usa npm/Node. Ignore "npm: command not found".
Modo: CLI/HTTP → cadastrar-relatorio-coach + 1 PDF consolidado via PHP/Dompdf (TUTORY_PDF_ENGINE=dompdf)
[Mychel] TUTORY_PDF_ENGINE=dompdf — gerando o consolidado com PHP/Dompdf
```

## Modelos-fonte (`RELATORIOS`)

Definidos em `CoachReportDownloader::RELATORIOS`:

| model (URL) | Nome no menu |
|-------------|--------------|
| `desempenho` | Desempenho |
| `aluno` | Estudos |
| `horas-liquidas` | Horas Líquidas |
| `questoes` | Desempenho em Questões |
| `progresso` | Progresso do plano |

Os cinco modelos são os botões de `#modalGeraRelatorio` em Pesquisar Alunos. O token gerado em `cadastrar-relatorio-coach` vale para todos: muda só o segmento `/documentos/relatorios/{model}`.

## Requisitos

- PHP 8.2+ com `php-gd` (Dompdf)
- Rede para `admin.tutory.com.br` (e `quickchart.io` só para os PDFs individuais)
- Node.js 18+ com `puppeteer` — **opcional**; só se quiser o compositor fiel ao navegador
- SMTP configurado (`MAIL_*`) para envio dos relatórios
- Alunos cadastrados em `/admin/alunos` com o **mesmo nome** usado no Tutory (para casar o PDF)

## `.env`

```env
LOGIN_URL=https://admin.tutory.com.br/login
LOGIN_USER=
LOGIN_PASSWORD=
# Preferível fora de public/ (evita expor HTML/token)
PASTA_DOWNLOAD=
TIMEOUT=120
APP_TIMEZONE=America/Sao_Paulo
TUTORY_PDF_ENGINE=dompdf
# NODE_BINARY=
# TUTORY_REPORT_GENERATE_URL=/intent/cadastrar-relatorio-coach
# TUTORY_REPORT_AGRUPAMENTO=dia
# MAIL_BCC_ADDRESS=nayara@missaonomeacao.com.br
```

Em teste, o serviço pode usar credenciais hardcoded. Em produção, use `LOGIN_USER` / `LOGIN_PASSWORD`.

Se `PASTA_DOWNLOAD` estiver vazio, usa `public/pdfs`.

## Uso

```bash
# Regenerar só o Mychel (modo --teste):
# período 1 = dias 01–15 do mês corrente
# período 2 = dia 16 até o último dia do mês (no dia 1–15, usa o mês anterior)
php artisan tutory:baixar-relatorios --periodo=1 --teste
php artisan tutory:baixar-relatorios --periodo=2 --teste
```

Arquivo gerado: `relatorio_consolidado_{Ymd_Hi}_{aluno}_{periodo}.pdf`  
Exemplo: `relatorio_consolidado_20260818_2230_Giovanna_1.pdf`

O envio de e-mail anexa o PDF consolidado mais recente do aluno para o `--periodo` informado. Lead, inscrição e relatório vão **com CCO** para `MAIL_BCC_ADDRESS` (padrão: `nayara@missaonomeacao.com.br`). O deploy da GitHub Action **não substitui** o `.env` da Hostinger; se a variável não existir no servidor, o padrão do `config/mail.php` continua valendo. Depois do envio do relatório, **todos os PDFs da pasta são apagados**.

## Contingência (admin)

Em `/admin/relatorios-pdf-contingencia` a mentora gera o PDF de **um aluno** e **um período já liberado**. Há duas ações:

- **Baixar PDF** — só o download, sem e-mail (comportamento anterior).
- **Gerar e enviar e-mail** — gera o consolidado na Tutory, anexa no e-mail do aluno e envia com o mesmo CCO dos relatórios oficiais. O download continua disponível. A contingência **não grava** as faixas no dashboard (pode ser um período antigo).

O reenvio manual segue mesmo se `recebe_email` estiver desmarcado. Não envia para a mentora (`Aluno::isTeacher()`) nem para cadastro com e-mail inválido.

### Onde ver os erros

1. Overlay da própria tela (passo a passo, mensagem de SMTP e aviso se o PDF saiu mas o e-mail não).
2. Log dedicado: `storage/logs/pdf-contingencia.log` (no servidor: `server/storage/logs/pdf-contingencia.log`).
3. Log geral: `storage/logs/laravel.log` (as falhas também são gravadas aqui).

```bash
tail -n 80 storage/logs/pdf-contingencia.log
grep -n "contingência" storage/logs/laravel.log
```

Para renderizar um modelo isolado (debug, não usado no e-mail):

```bash
node scripts/tutory-render-pdf.mjs --url "https://admin.tutory.com.br/documentos/relatorios/questoes?key=..." --out /tmp/q.pdf --model questoes
```

## Agendamento

O Laravel **não dispara sozinho**. O fuso dos jobs é **America/Sao_Paulo** (UTC−3 o ano inteiro), não o relógio UTC do servidor. Cada job só roda no minuto da tabela e nos **4 minutos seguintes**. A sincronização das 06:00 não roda às 11:05.

O relógio é um cron **de todo minuto** na Hostinger, que chama `scripts/tutory-scheduler.sh`. O PHP é que decide se aquele minuto é o horário do job. O plano Web/Cloud não tem `crontab` pelo SSH. O deploy cria o cron pela API da Hostinger quando o secret `HOSTINGER_API_TOKEN` existe; se não existir, cadastre no hPanel → Cron Jobs → Custom, uma vez por minuto:

```text
/bin/sh /home/USUARIO/domains/missaonomeacao.com.br/public_html/server/scripts/tutory-scheduler.sh
```

A Action do GitHub chama o mesmo comando só nos horários equivalentes em UTC. Uma visita ao site também chama o comando, mas só se o relógio já estiver dentro desses 4 minutos, e sem gerar PDF.

| Job | Comando | Quando (America/Sao_Paulo) |
|-----|---------|--------|
| Sincronizar alunos | `tutory:sincronizar-alunos` | Todo dia, **06:00** |
| Periodo 1 | `tutory:baixar-relatorios --periodo=1 --se-pendente` | Dia **16**, **10:30**. Se não enviou, de hora em hora às **11:00–22:00** nos dias **16** e **17** |
| Periodo 2 | `tutory:baixar-relatorios --periodo=2 --se-pendente` | Dia **1**, **10:30**. Se não enviou, de hora em hora às **11:00–22:00** nos dias **1** e **2** |
| Liberar períodos no admin | `tutory:liberar-periodos-pdf` | Dias **1** e **16**, **00:05** |

`--se-pendente` grava em `configuracoes` e evita e-mail duplicado se o cron e a Action rodarem no mesmo período. A trava do sync (`tutory.job.sincronizar-alunos.YYYY-MM-DD`) vale só para o agendado; rodar `tutory:sincronizar-alunos` à mão não é bloqueado por ela. O botão **Sincronizar alunos** em `/admin/alunos` executa o mesmo serviço, a qualquer momento, e também não grava essa trava nem altera o horário das 06:00.

A Tutory sempre envia um cadastro chamado **Aluno teste**. O job ignora esse nome (maiúsculas/minúsculas e espaços extras não importam) e **não o cadastra** na tabela local.

O nome no admin é único. Se a Tutory devolver o mesmo nome com **outro** `tutory_id` (cadastro recriado na Tutory), o sync **atualiza** o id local em vez de pular como duplicata. Quem está em `status=desativados` também é localizado por nome, mesmo com id antigo, e fica `ativo=false`.

Nos alunos **ativos**, o sync abre Ações → Cadastro (`/alunos/index?aid={id}`) e grava `telefone` como `55` + DDD + número, só dígitos. O valor da Tutory prevalece sobre o que estiver no admin. Alunos desativados não têm o telefone consultado.

Para inspecionar no servidor:

```bash
php artisan tutory:scheduler-status
```

Para rodar agora, sem esperar o agendamento:

```bash
php artisan tutory:sincronizar-alunos
php artisan tutory:baixar-relatorios --periodo=1
```

Conferir: `php artisan schedule:list` e `php artisan tutory:scheduler-status` (o heartbeat mostra o último tick, seja da visita, do cron ou da Action).

## Gestão de desempenho (admin)

Em `/admin/desempenho` o mentor edita as **faixas e textos** dos eixos do documento de parâmetros. O e-mail do aluno recebe um bloco por eixo aplicável.

As métricas saem dos HTMLs oficiais de Progresso e Questões (mesmo cálculo de antes) e vão no sidecar `.metricas.json` do PDF consolidado.

| Eixo | Métrica | Origem |
|------|---------|--------|
| Constância na quinzena | `dias_falhados = dias_analisados − dias_estudados` | Progresso → `#chart_horas_diarias` |
| Quantidade total de questões | `total_questoes` | Questões → `.main-numbers` |
| Percentual geral de acertos | `% acertos` (só se ≥ 100 questões) | Questões → `.main-numbers` |
| Percentual por assunto | assuntos com % ≤ 75 | Questões → `#tabela_questoes` |

Faixas padrão (seed):

- **Constância:** 0 excelente · 1–3 bom · 4–10 brigando · ≥11 crítico  
- **Volume:** 0–49 crítico · 50–99 baixo · 100–500 suficiente · >500 alto  
- **% geral:** ≤60 crítico · ≤70 alerta · <80 mediano · <90 muito bom · ≤100 excelente  
- **Assunto:** um único bloco com N bullets (≤60 crítico · ≤75 abaixo da média); texto de acompanhamento após a lista  


Placeholders nos textos: `{NOME}`, `{X}`, `{Y}`, `{Z}`, `{TOTAL_QUESTOES}`, `{PERCENTUAL_ACERTOS}`, `{ASSUNTO}`, `{PERCENTUAL}`.

```bash
php artisan migrate
php artisan db:seed --class=ParametrosDesempenhoSeeder
```

## Segurança

- **Não** salve dumps de HTML do admin em `public/` (o `adminUser.token` vaza).
- Prefira `LOGIN_USER` / `LOGIN_PASSWORD` no `.env` (hardcode só para teste).
- Se um `debug_*.html` já ficou público, revogue a sessão no Tutory e apague o arquivo.
- Os PDFs gerados são apagados da pasta de download **depois do envio** dos e-mails.
