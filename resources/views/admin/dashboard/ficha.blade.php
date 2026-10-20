@php
    use App\Enums\AcaoAcompanhamento;
    use App\Enums\TendenciaFaixa;
    use App\Services\Acompanhamento\TextoAcompanhamento;

    $planoEncerrado = $linha->ficha->acao === AcaoAcompanhamento::RestabelecerContato;
    $linkWhatsapp = $linha->aluno->linkWhatsappWeb() ?? '';
    $dataProximo = optional($linha->aluno->proximo_contato_em)->toDateString();
    $hojeData = now()->toDateString();
    $estilo = match ($linha->ficha->acao) {
        AcaoAcompanhamento::Intervir => 'bg-red-100 text-red-800',
        AcaoAcompanhamento::MarcarPresenca => 'bg-primary/10 text-primary',
        AcaoAcompanhamento::Parabenizar => 'bg-green-100 text-green-800',
        AcaoAcompanhamento::Ok, AcaoAcompanhamento::RestabelecerContato => 'bg-gray-100 text-gray-700',
        AcaoAcompanhamento::AlunoNovato => 'bg-amber-100 text-amber-900',
    };
    $rotuloAcao = $linha->ficha->acao->rotulo();
    $temPonte = collect($linha->ficha->motivos)->contains(fn ($motivo) => $motivo->ponteProtocoloResgate);
    $itensDestaque = [];
    if ($planoEncerrado) {
        $itensDestaque[] = [
            'tipo' => 'plano_encerrado',
            'texto' => 'Plano de estudos encerrado',
            'ponte' => false,
        ];
    } else {
        foreach ($linha->ficha->motivos as $motivo) {
            $itensDestaque[] = [
                'tipo' => $motivo->tipo->value,
                'texto' => $motivo->texto,
                'ponte' => $motivo->ponteProtocoloResgate,
            ];
        }
    }
@endphp

<aside class="fixed inset-y-0 right-0 z-40 flex w-full max-w-[420px] flex-col border-l border-gray-200 bg-white shadow-lg">
    <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
        <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">{{ $linha->iniciais }}</span>
            <div>
                <h2 class="text-lg font-bold text-gray-800">{{ $linha->aluno->nome }}</h2>
                @if(filled($linha->aluno->telefone))
                    <p class="text-sm text-gray-600">{{ $linha->aluno->telefone }}</p>
                @endif
                <a href="{{ route('alunos.edit', $linha->aluno) }}" class="text-sm font-medium text-primary hover:text-primary-light">Ver ficha do aluno</a>
            </div>
        </div>
        <a href="{{ $consulta->url(['aluno' => null]) }}" class="rounded p-2 text-gray-600 hover:bg-gray-100" aria-label="Fechar ficha">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </a>
    </div>

    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
        <div class="flex items-center justify-between gap-3">
            <span data-acao-ficha="{{ $linha->ficha->acao->value }}" class="inline-flex items-center gap-2 rounded px-3 py-1 text-sm font-medium {{ $estilo }}">
                {{ $rotuloAcao }}
            </span>
            <span class="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">{{ $linha->situacao->rotulo() }}</span>
        </div>

        <section>
            <h3 class="text-sm font-semibold text-gray-700">Evolução dos parâmetros</h3>
            <div class="mt-3 space-y-4">
                @foreach($linha->ficha->evolucoes as $evolucao)
                    @php
                        $cor = match ($evolucao->tendencia) {
                            TendenciaFaixa::Melhorou => 'text-green-800',
                            TendenciaFaixa::Piorou => 'text-red-800',
                            default => 'text-gray-500',
                        };
                    @endphp
                    <div>
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-gray-800">{{ $evolucao->parametro->rotulo() }}</span>
                            <span class="{{ $cor }} font-semibold">{{ $evolucao->tendencia->simbolo() }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-3 text-xs text-gray-600">
                            <span>Anterior: {{ $evolucao->anterior ?: '—' }}</span>
                            <span>Atual: {{ $evolucao->atual ?: '—' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        @if($linha->assuntos !== [])
            <section>
                <h3 class="text-sm font-semibold text-gray-700">Desempenho por assunto</h3>
                <ul class="mt-3 space-y-2">
                    @foreach(array_slice($linha->assuntos, 0, 4) as $assunto)
                        <li class="flex items-center justify-between gap-3 rounded border border-gray-300 px-3 py-2">
                            <span class="text-sm text-gray-700">
                                {{ $assunto['disciplina'] !== '' ? $assunto['disciplina'].' · ' : '' }}{{ $assunto['assunto'] }}
                            </span>
                            <span class="shrink-0 rounded bg-red-100 px-2 py-1 text-xs font-medium text-red-800">{{ $assunto['faixa_nome'] }}</span>
                        </li>
                    @endforeach
                </ul>
                @if(count($linha->assuntos) > 4)
                    <p class="mt-2 text-xs text-gray-500">+ {{ count($linha->assuntos) - 4 }} assuntos na ficha</p>
                @endif
            </section>
        @endif

        <section class="rounded bg-gray-50 p-4">
            <h3 class="text-sm font-semibold text-gray-700">Acompanhamento</h3>
            <h4 class="mt-4 text-sm font-semibold text-gray-800">O que deve ser destacado neste acompanhamento?</h4>
            <ul class="mt-3 space-y-2">
                @foreach($itensDestaque as $item)
                    <li data-motivo="{{ $item['tipo'] }}" @class([
                        'rounded border border-gray-200 border-l-4 px-3 py-2.5 text-sm font-medium leading-5 text-gray-800 shadow-sm',
                        'border-l-green-700 bg-green-50' => $item['tipo'] === 'panorama',
                        'border-l-primary-light bg-white' => $item['tipo'] !== 'panorama',
                    ])>
                        <span class="mr-2 text-base text-primary-light" aria-hidden="true">◆</span>{{ $item['texto'] }}
                        @if($item['ponte'])
                            <span class="mt-1 block pl-5 text-xs font-semibold text-primary-light">Ponte com o Protocolo de Resgate</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($temPonte && ! $planoEncerrado)
                <p class="mt-3 rounded border border-primary-light/40 bg-white px-3 py-2 text-xs font-medium leading-5 text-primary">
                    Desempenho baixo em assunto é o ponto em que o acompanhamento encontra o Protocolo de Resgate.
                </p>
            @endif
            <dl class="mt-4 space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-gray-500">Último contato</dt>
                    <dd class="font-medium text-gray-800">{{ $linha->ultimoContato }}</dd>
                </div>
                @if($linha->observacaoNovato || $linha->observacaoTempoSemContato)
                    <div>
                        <dt class="text-xs text-gray-500">Observação</dt>
                        @if($linha->observacaoNovato)
                            <dd class="font-medium text-gray-800">{{ $linha->observacaoNovato }}</dd>
                        @endif
                        @if($linha->observacaoTempoSemContato)
                            <dd class="font-medium text-gray-800">{{ $linha->observacaoTempoSemContato }}</dd>
                        @endif
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-gray-500">Última observação</dt>
                    <dd class="text-gray-700">{{ $linha->aluno->ultima_observacao ?: 'Nenhuma observação' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Próximo contato</dt>
                    <dd class="mt-1 flex items-center justify-between gap-3">
                        <span class="font-medium text-gray-800">{{ $linha->proximoContato === '—' ? 'Nenhum agendado' : $linha->proximoContato }}</span>
                        @if($dataProximo)
                            <button type="button" id="abrir-remarcar-data" class="shrink-0 rounded border border-primary px-3 py-1.5 text-xs font-medium text-primary transition hover:bg-primary hover:text-white">Remarcar data</button>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        @if($errors->any())
            <div class="rounded border border-red-300 bg-red-100 px-3 py-2 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <form id="form-registrar-contato" method="POST" action="{{ route('admin.dashboard.contatos.store', $linha->aluno) }}?{{ http_build_query($consulta->parametros(['aluno' => $linha->aluno->id, 'page' => null])) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="itens_abordados" value="">
            <label class="block">
                <span class="text-sm font-semibold text-gray-700">Data do contato <span class="text-red-800" aria-hidden="true">*</span></span>
                <input type="date" name="ocorrido_em" required max="{{ $hojeData }}" value="{{ old('ocorrido_em', $hojeData) }}" class="mt-2 w-full rounded border border-gray-300 p-3 text-sm text-gray-800 focus:border-primary focus:ring-primary">
            </label>
            <label class="block">
                <span class="text-sm font-semibold text-gray-700">Observação do contato <span class="text-red-800" aria-hidden="true">*</span></span>
                <textarea name="observacao" rows="3" required class="mt-2 w-full rounded border border-gray-300 p-3 text-sm text-gray-800 focus:border-primary focus:ring-primary" placeholder="O que foi combinado neste contato?">{{ old('observacao') }}</textarea>
            </label>
            <button type="submit" class="flex w-full items-center justify-center gap-2 rounded bg-primary px-4 py-3 text-sm font-medium text-white transition hover:bg-primary-light">
                Registrar contato
            </button>
        </form>

        @if($linkWhatsapp !== '')
            <a href="{{ $linkWhatsapp }}" target="_blank" data-abrir="{{ route('admin.dashboard.whatsapp', $linha->aluno) }}" onclick="var j=window.open(this.dataset.abrir,'missao-whatsapp'); if(j){ j.focus(); return false; }" class="flex w-full items-center justify-center gap-2 rounded border border-primary bg-white px-4 py-3 text-sm font-medium text-primary transition hover:bg-primary hover:text-white">
                Conversar com aluno
            </a>
        @endif

        @if(! $dataProximo)
            <form method="POST" action="{{ route('admin.dashboard.agenda.store', $linha->aluno) }}" class="space-y-3">
                @csrf
                <label class="block">
                    <span class="text-sm font-semibold text-gray-700">Agendar acompanhamento</span>
                    <input type="date" name="proximo_contato_em" value="{{ old('proximo_contato_em') }}" class="mt-2 w-full rounded border border-gray-300 p-3 text-sm text-gray-800 focus:border-primary focus:ring-primary">
                </label>
                <button type="submit" class="w-full rounded bg-gray-200 px-4 py-3 text-sm font-medium text-gray-800 transition hover:bg-gray-300">Salvar data</button>
            </form>
        @endif
        @if($dataProximo)
            <form method="POST" action="{{ route('admin.dashboard.agenda.store', $linha->aluno) }}">
                @csrf
                <input type="hidden" name="limpar" value="1">
                <button type="submit" class="text-sm font-medium text-red-800 hover:underline">Cancelar agendamento</button>
            </form>
        @endif

        <details class="rounded border border-gray-300 px-4 py-3">
            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Ver histórico de contatos</summary>
            <ul class="mt-3 space-y-3">
                @forelse($contatos as $contato)
                    <li class="border-t border-gray-100 pt-3 text-sm first:border-0 first:pt-0">
                        <p class="text-xs text-gray-500">{{ TextoAcompanhamento::dataHora($contato->ocorrido_em) }}</p>
                        <p class="mt-1 text-gray-700">{{ $contato->observacao ?: 'Contato sem observação' }}</p>
                    </li>
                @empty
                    <li class="text-sm text-gray-600">Nenhum contato registrado.</li>
                @endforelse
            </ul>
        </details>
    </div>

    @if($dataProximo)
        <dialog id="dialogo-remarcar-data" class="w-full max-w-md rounded border border-gray-200 bg-white p-6 shadow-lg backdrop:bg-black/40" aria-labelledby="dialogo-remarcar-titulo">
            <form method="POST" action="{{ route('admin.dashboard.agenda.store', $linha->aluno) }}">
                @csrf
                <input type="hidden" name="remarcar" value="1">
                <h4 id="dialogo-remarcar-titulo" class="text-base font-semibold text-gray-800">Remarcar próximo contato</h4>
                <p class="mt-2 text-sm leading-6 text-gray-600">A data atual é {{ $linha->aluno->proximo_contato_em->format('d/m/Y') }}. Cancelar mantém essa data.</p>
                <label class="mt-4 block">
                    <span class="text-sm font-semibold text-gray-700">Nova data</span>
                    <input type="date" name="proximo_contato_em" required data-data-original="{{ $dataProximo }}" value="{{ old('proximo_contato_em', $dataProximo) }}" class="mt-2 w-full rounded border border-gray-300 p-3 text-sm text-gray-800 focus:border-primary focus:ring-primary">
                </label>
                <div class="mt-5 flex flex-col gap-2">
                    <button type="submit" class="rounded bg-primary px-4 py-3 text-sm font-medium text-white transition hover:bg-primary-light">Salvar nova data</button>
                    <button type="button" id="cancelar-remarcar-data" class="rounded bg-gray-200 px-4 py-3 text-sm font-medium text-gray-800 transition hover:bg-gray-300">Cancelar</button>
                </div>
            </form>
        </dialog>
    @endif

    <dialog id="dialogo-itens-abordados" class="w-full max-w-md rounded border border-gray-200 bg-white p-6 shadow-lg backdrop:bg-black/40" aria-labelledby="dialogo-itens-titulo">
        <h4 id="dialogo-itens-titulo" class="text-base font-semibold text-gray-800">Todos os itens do acompanhamento foram abordados?</h4>
        <ul class="mt-4 space-y-2">
            @foreach($itensDestaque as $item)
                <li class="rounded border border-gray-200 border-l-4 border-l-primary-light bg-gray-50 px-3 py-2 text-sm font-medium text-gray-800">{{ $item['texto'] }}</li>
            @endforeach
        </ul>
        <div class="mt-5 flex flex-col gap-2">
            <button type="button" id="confirmar-itens-sim" class="rounded bg-primary px-4 py-3 text-sm font-medium text-white transition hover:bg-primary-light">Sim, todos foram abordados</button>
            <button type="button" id="confirmar-itens-nao" class="rounded bg-gray-200 px-4 py-3 text-sm font-medium text-gray-800 transition hover:bg-gray-300">Não, ainda não</button>
        </div>
    </dialog>
</aside>
<script>
    (function () {
        var form = document.getElementById('form-registrar-contato');
        var dialogo = document.getElementById('dialogo-itens-abordados');
        var sim = document.getElementById('confirmar-itens-sim');
        var nao = document.getElementById('confirmar-itens-nao');
        if (!form || !dialogo || !sim || !nao) {
            return;
        }
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmado === '1') {
                return;
            }
            event.preventDefault();
            dialogo.showModal();
        });
        sim.addEventListener('click', function () {
            if (form.dataset.confirmado === '1') {
                return;
            }
            form.dataset.confirmado = '1';
            form.querySelector('[name="itens_abordados"]').value = '1';
            sim.disabled = true;
            dialogo.close();
            form.requestSubmit();
        });
        nao.addEventListener('click', function () {
            dialogo.close();
        });
    })();
    (function () {
        var abrir = document.getElementById('abrir-remarcar-data');
        var dialogo = document.getElementById('dialogo-remarcar-data');
        var cancelar = document.getElementById('cancelar-remarcar-data');
        if (!abrir || !dialogo || !cancelar) {
            return;
        }
        var input = dialogo.querySelector('[name="proximo_contato_em"]');
        function restaurarDataAnterior() {
            if (input) {
                input.value = input.getAttribute('data-data-original') || '';
            }
        }
        abrir.addEventListener('click', function () {
            restaurarDataAnterior();
            dialogo.showModal();
        });
        cancelar.addEventListener('click', function () {
            restaurarDataAnterior();
            dialogo.close();
        });
        dialogo.addEventListener('cancel', restaurarDataAnterior);
    })();
</script>
