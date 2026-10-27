<?php

namespace App\Services\Tutory;

/**
 * Dados do relatório quinzenal, já extraídos dos HTMLs oficiais da Tutory.
 *
 * Os valores entram como a Tutory entrega. O que é derivado (totais por
 * disciplina, plano cumprido, dias com questões) é calculado aqui, a partir
 * desses mesmos valores, para o PDF mostrar.
 */
final class DadosRelatorioQuinzenal
{
    /**
     * @param  list<string>  $datas  datas do período, no formato dd/mm
     * @param  list<?float>  $planejado  horas planejadas por dia
     * @param  list<?float>  $estudado  horas estudadas (brutas) por dia
     * @param  list<?float>  $brutas  horas brutas por dia (gráfico brutas × líquidas)
     * @param  list<?float>  $liquidas  horas líquidas por dia
     * @param  list<?float>  $acertosDia
     * @param  list<?float>  $errosDia
     * @param  list<array{label: string, value: string}>  $acumulado  cards de desempenho geral
     * @param  list<array{label: string, value: string}>  $insights  pares do Painel de Insights
     * @param  list<array{label: string, value: string}>  $panorama  números do panorama de questões
     * @param  list<array{disciplina: string, assunto: string, taxa: string}>  $assuntos
     * @param  array{headers: list<string>, rows: list<list<string>>}  $revisoes
     * @param  list<array{disciplina: string, assunto: string, modalidade: string, horas: string}>  $historico
     */
    public function __construct(
        public readonly string $aluno,
        public readonly string $curso,
        public readonly \DateTimeImmutable $inicio,
        public readonly \DateTimeImmutable $fim,
        public readonly array $datas = [],
        public readonly array $planejado = [],
        public readonly array $estudado = [],
        public readonly array $brutas = [],
        public readonly array $liquidas = [],
        public readonly array $acertosDia = [],
        public readonly array $errosDia = [],
        public readonly array $acumulado = [],
        public readonly array $insights = [],
        public readonly array $panorama = [],
        public readonly array $assuntos = [],
        public readonly array $revisoes = ['headers' => [], 'rows' => []],
        public readonly array $historico = [],
    ) {}

    /**
     * @var array<int, string>
     */
    private const MESES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    public function diasNoPeriodo(): int
    {
        return (int) $this->inicio->diff($this->fim)->days + 1;
    }

    /**
     * "16 a 30 de setembro" (ou "28 de setembro a 2 de outubro" quando muda o mês).
     */
    public function periodoPorExtenso(): string
    {
        $mesIni = self::MESES[(int) $this->inicio->format('n')];
        $mesFim = self::MESES[(int) $this->fim->format('n')];
        $diaIni = (int) $this->inicio->format('j');
        $diaFim = (int) $this->fim->format('j');
        if ($mesIni === $mesFim) {
            return "{$diaIni} a {$diaFim} de {$mesFim}";
        }

        return "{$diaIni} de {$mesIni} a {$diaFim} de {$mesFim}";
    }

    public function insight(string $rotulo): ?string
    {
        foreach ($this->insights as $par) {
            if (mb_strtoupper($par['label'], 'UTF-8') === mb_strtoupper($rotulo, 'UTF-8')) {
                return $par['value'];
            }
        }

        return null;
    }

    /**
     * Insight cujo rótulo contém todos os trechos (comparação sem acento e sem espaço).
     * A Tutory escreve, por exemplo, "Sua média de tempo por dia foi de 02:39".
     *
     * @param  list<string>  $trechos
     */
    public function insightContendo(array $trechos): ?string
    {
        foreach ($this->insights as $par) {
            $rotulo = self::normalizar($par['label']);
            $ok = true;
            foreach ($trechos as $t) {
                if (! str_contains($rotulo, self::normalizar($t))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok && trim($par['value']) !== '') {
                return $par['value'];
            }
        }

        return null;
    }

    public function mediaDiaria(): ?string
    {
        return $this->insightContendo(['média']);
    }

    /**
     * Curso como a Tutory manda, sem o rótulo "Curso:" na frente.
     */
    public function cursoLimpo(): string
    {
        $curso = trim(preg_replace('/\s+/u', ' ', $this->curso) ?? $this->curso);

        return trim(preg_replace('/^curso\s*:\s*/iu', '', $curso) ?? $curso);
    }

    /**
     * Nome da disciplina para exibir: sem o prefixo do curso e, se vier todo em
     * maiúsculas ("LEGISLAÇÃO DE TRÂNSITO"), em caixa de título ("Legislação de Trânsito").
     */
    public function nomeDisciplina(string $disciplina): string
    {
        return self::caixaDeTitulo($this->semPrefixoCurso(trim($disciplina)));
    }

    public static function caixaDeTitulo(string $texto): string
    {
        if ($texto === '' || mb_strtoupper($texto, 'UTF-8') !== $texto || ! preg_match('/\p{Lu}{2}/u', $texto)) {
            return $texto;
        }
        $minusculas = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'a', 'o', 'as', 'os', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com'];
        $palavras = preg_split('/(\s+)/u', mb_strtolower($texto, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($palavras as $i => $p) {
            if (trim($p) === '') {
                $out .= $p;

                continue;
            }
            if ($i > 0 && in_array($p, $minusculas, true)) {
                $out .= $p;

                continue;
            }
            if (preg_match('/^(i|ii|iii|iv|v|vi|vii|viii|ix|x)$/u', $p)) {
                $out .= mb_strtoupper($p, 'UTF-8');

                continue;
            }
            $out .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($p, 1, null, 'UTF-8');
        }

        return $out;
    }

    /**
     * @return array{total: ?int, acertos: ?int, percentual: ?string}
     */
    public function questoes(): array
    {
        $total = null;
        $acertos = null;
        $percentual = null;
        foreach ($this->panorama as $par) {
            $label = self::normalizar($par['label']);
            $valor = trim($par['value']);
            if (str_contains($valor, '%')) {
                $percentual ??= $valor;
            } elseif (str_contains($label, 'acerto')) {
                $acertos ??= self::inteiro($valor);
            } elseif (str_contains($label, 'quest') || str_contains($label, 'exerc') || str_contains($label, 'realizad')) {
                $total ??= self::inteiro($valor);
            }
        }

        $total ??= self::inteiro((string) $this->insight('EXERCÍCIOS REALIZADOS'));
        $acertos ??= self::inteiro((string) $this->insight('ACERTOS'));
        $percentual ??= $this->insight('TAXA DE ACERTOS');
        if ($percentual === null && $total !== null && $total > 0 && $acertos !== null) {
            $percentual = self::numeroBr($acertos / $total * 100, 1).'%';
        }

        return ['total' => $total, 'acertos' => $acertos, 'percentual' => $percentual];
    }

    /**
     * @return array{planejado: float, estudado: float, percentual: int}|null
     */
    public function planoCumprido(): ?array
    {
        $planejado = array_sum(array_map(static fn (?float $v): float => (float) $v, $this->planejado));
        $estudado = array_sum(array_map(static fn (?float $v): float => (float) $v, $this->estudado));
        if ($planejado <= 0) {
            return null;
        }

        return [
            'planejado' => $planejado,
            'estudado' => $estudado,
            'percentual' => (int) round($estudado / $planejado * 100),
        ];
    }

    /**
     * Dias com questões resolvidas: [data, acertos, erros].
     *
     * @return list<array{data: string, acertos: int, erros: int}>
     */
    public function diasComQuestoes(): array
    {
        $out = [];
        foreach ($this->datas as $i => $data) {
            $a = (int) round((float) ($this->acertosDia[$i] ?? 0));
            $e = (int) round((float) ($this->errosDia[$i] ?? 0));
            if ($a + $e > 0) {
                $out[] = ['data' => $data, 'acertos' => $a, 'erros' => $e];
            }
        }

        return $out;
    }

    /**
     * Histórico agrupado por disciplina, na ordem de mais horas para menos.
     *
     * @return list<array{disciplina: string, segundos: int, itens: list<array{assunto: string, modalidade: string, horas: string, segundos: int}>}>
     */
    public function historicoPorDisciplina(): array
    {
        $grupos = [];
        foreach ($this->historico as $linha) {
            $nome = $this->nomeDisciplina($linha['disciplina']);
            $seg = self::segundos($linha['horas']);
            $grupos[$nome] ??= ['disciplina' => $nome, 'segundos' => 0, 'itens' => []];
            $grupos[$nome]['segundos'] += $seg;
            $grupos[$nome]['itens'][] = [
                'assunto' => $linha['assunto'],
                'modalidade' => $linha['modalidade'],
                'horas' => $linha['horas'],
                'segundos' => $seg,
            ];
        }
        $grupos = array_values($grupos);
        usort($grupos, static fn (array $a, array $b): int => $b['segundos'] <=> $a['segundos']);
        foreach ($grupos as &$g) {
            usort($g['itens'], static fn (array $a, array $b): int => $b['segundos'] <=> $a['segundos']);
        }

        return $grupos;
    }

    public function horasDaDisciplina(string $disciplina): ?int
    {
        $alvo = self::normalizar($this->nomeDisciplina($disciplina));
        foreach ($this->historicoPorDisciplina() as $g) {
            if (self::normalizar($g['disciplina']) === $alvo) {
                return $g['segundos'];
            }
        }

        return null;
    }

    /**
     * Tira o prefixo do curso ("PMSC - Português" vira "Português") quando
     * todas as disciplinas do relatório compartilham o mesmo prefixo.
     */
    public function semPrefixoCurso(string $disciplina): string
    {
        $prefixo = $this->prefixoComum();
        if ($prefixo !== '' && str_starts_with($disciplina, $prefixo)) {
            $resto = trim(substr($disciplina, strlen($prefixo)));

            return $resto !== '' ? $resto : $disciplina;
        }

        return $disciplina;
    }

    private ?string $prefixoCache = null;

    private function prefixoComum(): string
    {
        if ($this->prefixoCache !== null) {
            return $this->prefixoCache;
        }
        $nomes = array_merge(
            array_column($this->historico, 'disciplina'),
            array_column($this->assuntos, 'disciplina'),
        );
        $prefixo = null;
        foreach ($nomes as $nome) {
            if (! preg_match('/^([^\s-][^-]{0,24}?\s+-\s+)\S/u', $nome, $m)) {
                $prefixo = '';
                break;
            }
            if ($prefixo === null) {
                $prefixo = $m[1];
            } elseif ($prefixo !== $m[1]) {
                $prefixo = '';
                break;
            }
        }

        return $this->prefixoCache = (string) $prefixo;
    }

    public static function segundos(string $hhmmss): int
    {
        if (! preg_match('/^(\d{1,4}):(\d{2})(?::(\d{2}))?$/', trim($hhmmss), $m)) {
            return 0;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
    }

    /**
     * 45240 → "12h34".
     */
    public static function horasCurtas(int $segundos): string
    {
        $h = intdiv($segundos, 3600);
        $m = (int) round(($segundos % 3600) / 60);
        if ($m === 60) {
            $h++;
            $m = 0;
        }

        return $h.'h'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    public static function numeroBr(float $valor, int $casas = 1): string
    {
        $txt = number_format($valor, $casas, ',', '.');
        if ($casas > 0) {
            $txt = rtrim(rtrim($txt, '0'), ',');
        }

        return $txt;
    }

    public static function percentual(string $texto): ?float
    {
        $t = str_replace(['%', ' '], '', trim($texto));
        $t = str_replace(',', '.', $t);

        return is_numeric($t) ? (float) $t : null;
    }

    private static function inteiro(string $valor): ?int
    {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';

        return $digitos === '' ? null : (int) $digitos;
    }

    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return preg_replace('/[^a-z0-9%]+/', '', is_string($ascii) && $ascii !== '' ? $ascii : $texto) ?? '';
    }
}
