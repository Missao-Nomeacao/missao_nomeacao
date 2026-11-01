@extends('layouts.admin')

@section('title', 'Contingência de relatórios')

@section('content')
<div>
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Contingência de relatórios</h1>
    </div>

    @if(session('success'))
    <div class="p-4 mb-6 bg-green-100 text-green-800 rounded border border-green-300">
        {{ session('success') }}
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 mb-6 bg-red-100 text-red-800 rounded border border-red-300">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
        <form id="form-pdf-contingencia" action="{{ route('relatorios-pdf-contingencia.gerar') }}" method="POST" class="bg-white p-8 rounded shadow-lg">
            @csrf
            <input type="hidden" name="progress_token" id="progress-token" value="{{ $progressToken }}">

            <h2 class="text-lg font-bold text-gray-800 mb-1">Gerar PDF e reenviar e-mail</h2>
            <p class="text-sm text-gray-500 mb-6">Baixa o consolidado ou gera de novo na Tutory e reenvia o e-mail do aluno, com CCO para a mentora. Falhas de SMTP e de geração aparecem no overlay e em <code class="text-xs bg-gray-100 px-1 rounded">storage/logs/pdf-contingencia.log</code>.</p>
            <div class="mb-6">
                <span class="block text-sm font-semibold text-gray-700">Aluno *</span>
                <div id="combo-aluno" class="mt-2">
                    <select id="campo-aluno" name="aluno_id" required class="sr-only" tabindex="-1" aria-hidden="true">
                        <option value="">Selecione um aluno ativo da Tutory</option>
                        @foreach($alunos as $aluno)
                        <option value="{{ $aluno->id }}" {{ (string) old('aluno_id') === (string) $aluno->id ? 'selected' : '' }}>
                            {{ $aluno->nome }}
                        </option>
                        @endforeach
                    </select>
                    <button type="button" id="combo-aluno-btn" class="w-full rounded border border-gray-300 p-3 bg-white text-left flex items-center justify-between gap-3 focus:ring-primary focus:border-primary @error('aluno_id') border-red-500 @enderror" aria-haspopup="listbox" aria-expanded="false" aria-controls="combo-aluno-lista" {{ $alunos->isEmpty() ? 'disabled' : '' }}>
                        <span id="combo-aluno-label" class="truncate {{ old('aluno_id') ? 'text-gray-800' : 'text-gray-500' }}">
                            @php
                            $alunoSelecionado = $alunos->firstWhere('id', (int) old('aluno_id'));
                            @endphp
                            {{ $alunoSelecionado->nome ?? 'Selecione um aluno ativo da Tutory' }}
                        </span>
                        <svg class="h-4 w-4 text-gray-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <ul id="combo-aluno-lista" class="hidden mt-1 max-h-48 overflow-y-auto rounded border border-gray-300 bg-white shadow-inner" role="listbox" aria-label="Alunos ativos da Tutory">
                        <li role="option" data-value="" data-label="Selecione um aluno ativo da Tutory" class="px-3 py-2 text-sm text-gray-500 cursor-pointer hover:bg-yellow-50">
                            Selecione um aluno ativo da Tutory
                        </li>
                        @foreach($alunos as $aluno)
                        <li role="option" data-value="{{ $aluno->id }}" data-label="{{ $aluno->nome }}" class="px-3 py-2 text-sm text-gray-800 cursor-pointer hover:bg-yellow-50 {{ (string) old('aluno_id') === (string) $aluno->id ? 'bg-yellow-50 font-medium' : '' }}">
                            {{ $aluno->nome }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @if($alunos->isEmpty())
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-3 mt-3">Nenhum aluno com id da Tutory. Rode a sincronização de alunos ativos e volte aqui.</p>
                @endif
            </div>

            <div class="mb-8">
                <span class="block text-sm font-semibold text-gray-700">Mês / período *</span>
                @php
                $periodoPadrao = old('periodo', $periodos === [] ? '' : ($periodos[array_key_last($periodos)]['chave'] ?? ''));
                $periodoSelecionado = collect($periodos)->firstWhere('chave', $periodoPadrao);
                @endphp
                <div id="combo-periodo" class="mt-2">
                    <select id="campo-periodo" name="periodo" required class="sr-only" tabindex="-1" aria-hidden="true">
                        @forelse($periodos as $periodo)
                        <option value="{{ $periodo['chave'] }}" {{ (string) $periodoPadrao === (string) $periodo['chave'] ? 'selected' : '' }}>
                            {{ $periodo['label'] }}
                        </option>
                        @empty
                        <option value="">Nenhum período liberado</option>
                        @endforelse
                    </select>
                    <button type="button" id="combo-periodo-btn" class="w-full rounded border border-gray-300 p-3 bg-white text-left flex items-center justify-between gap-3 focus:ring-primary focus:border-primary @error('periodo') border-red-500 @enderror" aria-haspopup="listbox" aria-expanded="false" aria-controls="combo-periodo-lista" {{ $periodos === [] ? 'disabled' : '' }}>
                        <span id="combo-periodo-label" class="truncate {{ $periodoSelecionado ? 'text-gray-800' : 'text-gray-500' }}">
                            {{ $periodoSelecionado['label'] ?? 'Selecione o período' }}
                        </span>
                        <svg class="h-4 w-4 text-gray-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <ul id="combo-periodo-lista" class="hidden mt-1 max-h-48 overflow-y-auto rounded border border-gray-300 bg-white shadow-inner" role="listbox" aria-label="Meses e períodos">
                        @forelse($periodos as $periodo)
                        <li role="option" data-value="{{ $periodo['chave'] }}" data-label="{{ $periodo['label'] }}" class="px-3 py-2 text-sm text-gray-800 cursor-pointer hover:bg-yellow-50 {{ (string) $periodoPadrao === (string) $periodo['chave'] ? 'bg-yellow-50 font-medium' : '' }}">
                            {{ $periodo['label'] }}
                        </li>
                        @empty
                        <li role="option" data-value="" data-label="Nenhum período liberado" class="px-3 py-2 text-sm text-gray-500">
                            Nenhum período liberado
                        </li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row justify-end gap-3">
                <button id="btn-gerar-pdf" type="submit" data-acao="baixar" class="px-6 py-3 bg-white border border-primary text-primary hover:bg-gray-50 rounded font-medium transition" {{ $alunos->isEmpty() || $periodos === [] ? 'disabled' : '' }}>
                    Baixar PDF
                </button>
                <button id="btn-enviar-email" type="submit" data-acao="enviar" class="px-6 py-3 bg-primary hover:bg-primary-light text-white rounded font-medium transition" {{ $alunos->isEmpty() || $periodos === [] ? 'disabled' : '' }}>
                    Gerar e enviar e-mail
                </button>
            </div>
        </form>

        <form id="form-janela-meses" action="{{ route('relatorios-pdf-contingencia.update') }}" method="POST" class="bg-white p-8 rounded shadow-lg h-fit">
            @csrf
            @method('PUT')
            <h2 class="text-lg font-bold text-gray-800 mb-1">Período de busca</h2>
            <p class="text-sm text-gray-500 mb-6">Só é possível buscar relatórios de janeiro de 2026 em diante.</p>

            <label class="block mb-6">
                <span class="text-sm font-semibold text-gray-700">Meses visíveis *</span>
                <input type="number" name="meses" min="{{ \App\Services\Tutory\RelatorioPeriodoCatalog::MESES_MIN }}" max="{{ $mesesMaximos }}" value="{{ old('meses', $mesesVisiveis) }}" required class="mt-2 w-full rounded border border-gray-300 p-3 focus:ring-primary focus:border-primary @error('meses') border-red-500 @enderror" data-max-meses="{{ $mesesMaximos }}">
                @error('meses') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
            </label>

            <div class="flex justify-end">
                <button type="submit" class="px-6 py-3 bg-primary hover:bg-primary-light text-white rounded font-medium transition">
                    Salvar janela
                </button>
            </div>
        </form>
    </div>
</div>

<div id="overlay-pdf" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0, 29, 61, 0.72);" role="dialog" aria-modal="true" aria-labelledby="overlay-pdf-titulo">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg mx-4 p-8">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 id="overlay-pdf-titulo" class="text-xl font-bold text-gray-800">Gerando PDF</h2>
                <p id="overlay-pdf-subtitulo" class="text-sm text-gray-500 mt-1">Isso pode levar alguns minutos. Não feche esta página.</p>
            </div>
            <span id="overlay-pdf-timer" class="text-sm font-mono text-gray-600">00:00</span>
        </div>

        <div class="h-2 bg-gray-200 rounded overflow-hidden mb-6">
            <div id="overlay-pdf-barra" class="h-full bg-yellow-600 transition-all duration-500" style="width: 8%;"></div>
        </div>

        <ol id="overlay-pdf-etapas" class="space-y-3 mb-6">
            <li data-step="1" class="flex items-start gap-3 text-sm text-gray-500">
                <span class="etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs">1</span>
                <span>Conectando à Tutory</span>
            </li>
            <li data-step="2" class="flex items-start gap-3 text-sm text-gray-500">
                <span class="etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs">2</span>
                <span>Gerando os relatórios-fonte</span>
            </li>
            <li data-step="3" class="flex items-start gap-3 text-sm text-gray-500">
                <span class="etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs">3</span>
                <span>Montando o PDF consolidado</span>
            </li>
            <li data-step="4" class="flex items-start gap-3 text-sm text-gray-500">
                <span class="etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs">4</span>
                <span>Preparando o download</span>
            </li>
            <li id="overlay-pdf-etapa-email" data-step="5" class="hidden flex items-start gap-3 text-sm text-gray-500">
                <span class="etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs">5</span>
                <span>Enviando o e-mail</span>
            </li>
        </ol>

        <p id="overlay-pdf-log" class="text-xs text-gray-600 bg-gray-50 border border-gray-200 rounded p-3 min-h-[3.5rem]">Aguardando início…</p>
        <p id="overlay-pdf-erro" class="hidden mt-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded p-3"></p>
        <p id="overlay-pdf-aviso" class="hidden mt-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-3"></p>
        <div class="mt-6 flex justify-end">
            <button type="button" id="overlay-pdf-fechar" class="hidden px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded font-medium">Fechar</button>
        </div>
    </div>
</div>

<script>
    (function() {
        const form = document.getElementById('form-pdf-contingencia');
        const overlay = document.getElementById('overlay-pdf');
        const barra = document.getElementById('overlay-pdf-barra');
        const logEl = document.getElementById('overlay-pdf-log');
        const erroEl = document.getElementById('overlay-pdf-erro');
        const timerEl = document.getElementById('overlay-pdf-timer');
        const btn = document.getElementById('btn-gerar-pdf');
        const btnEnviar = document.getElementById('btn-enviar-email');
        const btnFechar = document.getElementById('overlay-pdf-fechar');
        const etapaEmail = document.getElementById('overlay-pdf-etapa-email');
        const avisoEl = document.getElementById('overlay-pdf-aviso');
        const tituloOverlay = document.getElementById('overlay-pdf-titulo');
        const subtituloOverlay = document.getElementById('overlay-pdf-subtitulo');
        const comboAluno = document.getElementById('combo-aluno');
        const comboAlunoBtn = document.getElementById('combo-aluno-btn');
        const comboAlunoLista = document.getElementById('combo-aluno-lista');
        const comboAlunoLabel = document.getElementById('combo-aluno-label');
        const campoAluno = document.getElementById('campo-aluno');
        const comboPeriodo = document.getElementById('combo-periodo');
        const comboPeriodoBtn = document.getElementById('combo-periodo-btn');
        const comboPeriodoLista = document.getElementById('combo-periodo-lista');
        const comboPeriodoLabel = document.getElementById('combo-periodo-label');
        const campoPeriodo = document.getElementById('campo-periodo');

        function ligarCombo(raiz, botao, lista, rotulo, campo) {
            if (!botao || !lista || !campo || !rotulo) {
                return {
                    abrir: function() {},
                    fechar: function() {}
                };
            }

            function fechar() {
                lista.classList.add('hidden');
                botao.setAttribute('aria-expanded', 'false');
            }

            function abrir() {
                if (botao.disabled) return;
                lista.classList.remove('hidden');
                botao.setAttribute('aria-expanded', 'true');
                const atual = lista.querySelector('[data-value="' + CSS.escape(campo.value || '') + '"]');
                if (atual && typeof atual.scrollIntoView === 'function') {
                    atual.scrollIntoView({
                        block: 'nearest'
                    });
                }
            }

            botao.addEventListener('click', function(ev) {
                ev.preventDefault();
                if (lista.classList.contains('hidden')) {
                    abrir();
                } else {
                    fechar();
                }
            });
            lista.querySelectorAll('[data-value]').forEach(function(item) {
                item.addEventListener('click', function() {
                    const valor = item.getAttribute('data-value') || '';
                    const label = item.getAttribute('data-label') || item.textContent.trim();
                    campo.value = valor;
                    rotulo.textContent = label;
                    rotulo.classList.toggle('text-gray-500', valor === '');
                    rotulo.classList.toggle('text-gray-800', valor !== '');
                    lista.querySelectorAll('[data-value]').forEach(function(li) {
                        li.classList.toggle('bg-yellow-50', li === item);
                        li.classList.toggle('font-medium', li === item);
                    });
                    fechar();
                });
            });
            document.addEventListener('click', function(ev) {
                if (raiz && !raiz.contains(ev.target)) {
                    fechar();
                }
            });
            document.addEventListener('keydown', function(ev) {
                if (ev.key === 'Escape') fechar();
            });

            return {
                abrir: abrir,
                fechar: fechar
            };
        }

        const alunoCombo = ligarCombo(comboAluno, comboAlunoBtn, comboAlunoLista, comboAlunoLabel, campoAluno);
        const periodoCombo = ligarCombo(comboPeriodo, comboPeriodoBtn, comboPeriodoLista, comboPeriodoLabel, campoPeriodo);

        const progressoUrl = @json(route('relatorios-pdf-contingencia.progresso'));
        const etapas = [8, 22, 45, 68, 88, 100];
        let pollId = null;
        let tickId = null;
        let startedAt = 0;
        let stepAtual = 1;
        let enviandoEmail = false;

        function botoes() {
            return [btn, btnEnviar].filter(Boolean);
        }

        function setBusy(busy) {
            botoes().forEach(function(b) {
                if (busy) {
                    b.disabled = true;
                    return;
                }
                b.disabled = {{ $alunos->isEmpty() || $periodos === [] ? 'true' : 'false' }};
            });
        }

        function pad(n) {
            return String(n).padStart(2, '0');
        }

        function pintarEtapas(step) {
            stepAtual = Math.max(stepAtual, step);
            document.querySelectorAll('#overlay-pdf-etapas li').forEach(function(li) {
                const n = parseInt(li.getAttribute('data-step'), 10);
                const icone = li.querySelector('.etapa-icone');
                if (n < stepAtual) {
                    li.className = 'flex items-start gap-3 text-sm text-green-700 font-medium';
                    icone.className = 'etapa-icone mt-0.5 w-5 h-5 rounded-full bg-green-600 text-white flex items-center justify-center text-xs';
                    icone.textContent = '✓';
                } else if (n === stepAtual) {
                    li.className = 'flex items-start gap-3 text-sm text-yellow-800 font-medium';
                    icone.className = 'etapa-icone mt-0.5 w-5 h-5 rounded-full bg-yellow-600 text-white flex items-center justify-center text-xs';
                    icone.textContent = String(n);
                } else {
                    li.className = 'flex items-start gap-3 text-sm text-gray-500';
                    icone.className = 'etapa-icone mt-0.5 w-5 h-5 rounded-full border border-gray-300 flex items-center justify-center text-xs';
                    icone.textContent = String(n);
                }
            });
            barra.style.width = etapas[Math.min(stepAtual, etapas.length - 1)] + '%';
        }

        function abrirOverlay() {
            stepAtual = 1;
            erroEl.classList.add('hidden');
            erroEl.textContent = '';
            if (avisoEl) {
                avisoEl.classList.add('hidden');
                avisoEl.textContent = '';
            }
            btnFechar.classList.add('hidden');
            if (tituloOverlay) {
                tituloOverlay.textContent = enviandoEmail ? 'Gerando e enviando' : 'Gerando PDF';
            }
            if (subtituloOverlay) {
                subtituloOverlay.textContent = enviandoEmail
                    ? 'O PDF é gerado, o e-mail sai em seguida e o download continua disponível.'
                    : 'Isso pode levar alguns minutos. Não feche esta página.';
            }
            if (etapaEmail) {
                etapaEmail.classList.toggle('hidden', !enviandoEmail);
            }
            logEl.textContent = 'Conectando à Tutory…';
            pintarEtapas(1);
            overlay.classList.remove('hidden');
            startedAt = Date.now();
            timerEl.textContent = '00:00';
            tickId = setInterval(function() {
                const s = Math.floor((Date.now() - startedAt) / 1000);
                timerEl.textContent = pad(Math.floor(s / 60)) + ':' + pad(s % 60);
                if (stepAtual < 3 && s > 12) pintarEtapas(2);
                if (stepAtual < 4 && s > 35) pintarEtapas(3);
            }, 250);
        }

        function pararTimers() {
            if (pollId) {
                clearInterval(pollId);
                pollId = null;
            }
            if (tickId) {
                clearInterval(tickId);
                tickId = null;
            }
        }

        function fecharOverlay() {
            pararTimers();
            overlay.classList.add('hidden');
            setBusy(false);
        }

        function mostrarErro(msg) {
            pararTimers();
            erroEl.textContent = msg || 'Falha ao gerar o PDF.';
            erroEl.classList.remove('hidden');
            btnFechar.classList.remove('hidden');
            setBusy(false);
            barra.style.width = '100%';
            barra.classList.remove('bg-yellow-600');
            barra.classList.add('bg-red-600');
        }

        function mostrarAvisoEmail(msg) {
            pararTimers();
            if (avisoEl) {
                avisoEl.textContent = msg || 'PDF gerado, mas o e-mail não saiu. Veja storage/logs/pdf-contingencia.log.';
                avisoEl.classList.remove('hidden');
            }
            btnFechar.classList.remove('hidden');
            setBusy(false);
            barra.style.width = '100%';
        }

        function nomeArquivo(res) {
            const cd = res.headers.get('Content-Disposition') || '';
            const star = /filename\*=UTF-8''([^;]+)/i.exec(cd);
            if (star) return decodeURIComponent(star[1]);
            const quoted = /filename="([^"]+)"/i.exec(cd);
            if (quoted) return quoted[1];
            const plain = /filename=([^;]+)/i.exec(cd);
            if (plain) return plain[1].trim().replace(/"/g, '');
            return 'relatorio-desempenho.pdf';
        }

        async function baixar(res) {
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = nomeArquivo(res);
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function() {
                URL.revokeObjectURL(url);
            }, 1500);
        }

        async function lerErro(res) {
            const tipo = (res.headers.get('Content-Type') || '').toLowerCase();
            if (tipo.indexOf('json') !== -1) {
                const json = await res.json();
                return json.message || (json.errors ? Object.values(json.errors).flat()[0] : null) || 'Falha ao gerar o PDF.';
            }
            return 'Falha ao gerar o PDF (HTTP ' + res.status + ').';
        }

        btnFechar.addEventListener('click', fecharOverlay);

        form.addEventListener('submit', async function(ev) {
            ev.preventDefault();
            const acao = (ev.submitter && ev.submitter.getAttribute('data-acao')) || 'baixar';
            enviandoEmail = acao === 'enviar';
            if (botoes().some(function(b) { return b.disabled && b.getAttribute('data-acao') === acao; })) return;
            if (!campoAluno || !campoAluno.value) {
                if (comboAlunoBtn) comboAlunoBtn.classList.add('border-red-500');
                alunoCombo.abrir();
                return;
            }
            if (!campoPeriodo || !campoPeriodo.value) {
                if (comboPeriodoBtn) comboPeriodoBtn.classList.add('border-red-500');
                periodoCombo.abrir();
                return;
            }
            setBusy(true);
            barra.classList.remove('bg-red-600');
            barra.classList.add('bg-yellow-600');
            abrirOverlay();

            const token = document.getElementById('progress-token').value;
            pollId = setInterval(async function() {
                try {
                    const r = await fetch(progressoUrl + '?token=' + encodeURIComponent(token), {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (!r.ok) return;
                    const data = await r.json();
                    if (data.message) logEl.textContent = data.message;
                    if (data.step) pintarEtapas(parseInt(data.step, 10) || 1);
                    if (data.status === 'error' && data.message) {
                        mostrarErro(data.message);
                    }
                } catch (e) {}
            }, 900);

            try {
                const payload = new FormData(form);
                payload.set('acao', acao);
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: payload,
                    headers: {
                        'Accept': 'application/json, application/pdf',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const tipo = (res.headers.get('Content-Type') || '').toLowerCase();
                if (!res.ok) {
                    mostrarErro(await lerErro(res));
                    return;
                }
                if (tipo.indexOf('pdf') === -1 && tipo.indexOf('octet-stream') === -1) {
                    mostrarErro(await lerErro(res));
                    return;
                }
                pintarEtapas(enviandoEmail ? 5 : 4);
                barra.style.width = '100%';
                logEl.textContent = enviandoEmail ? 'Enviando o e-mail e iniciando o download…' : 'PDF pronto. Iniciando o download…';
                await baixar(res);
                pararTimers();
                const emailStatus = (res.headers.get('X-Contingencia-Email') || '').toLowerCase();
                let emailMsg = res.headers.get('X-Contingencia-Email-Mensagem') || '';
                try {
                    emailMsg = decodeURIComponent(emailMsg);
                } catch (e) {}
                if (enviandoEmail && emailStatus === 'falhou') {
                    mostrarAvisoEmail(emailMsg || 'PDF gerado, mas o e-mail não saiu. Veja storage/logs/pdf-contingencia.log.');
                    logEl.textContent = 'Download iniciado. O e-mail falhou.';
                    return;
                }
                logEl.textContent = enviandoEmail
                    ? (emailMsg || 'E-mail enviado. Download iniciado.')
                    : 'Download iniciado.';
                btnFechar.classList.remove('hidden');
                setBusy(false);
            } catch (err) {
                mostrarErro(err && err.message ? err.message : 'Falha de rede ao gerar o PDF.');
            }
        });
    })();

    (function() {
        const input = document.querySelector('#form-janela-meses input[name="meses"]');
        if (!input) return;
        const max = parseInt(input.getAttribute('data-max-meses') || input.getAttribute('max') || '0', 10);
        const mensagem = 'A busca só alcança janeiro de 2026.';

        function validar() {
            const valor = Number(input.value);
            if (input.value !== '' && !Number.isNaN(valor) && valor > max) {
                input.setCustomValidity(mensagem);
            } else {
                input.setCustomValidity('');
            }
        }

        input.addEventListener('input', validar);
        input.addEventListener('invalid', function() {
            if (input.validity.rangeOverflow || (input.value !== '' && Number(input.value) > max)) {
                input.setCustomValidity(mensagem);
            }
        });
        validar();
    })();
</script>
@endsection