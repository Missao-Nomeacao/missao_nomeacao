<?php

namespace App\Services\Tutory;

/**
 * Gera um PDF de amostra, no mesmo layout do relatório quinzenal, para o preview do admin.
 *
 * Os números são um exemplo fixo (aluno Mychel, 16 a 30 de setembro), não dados reais.
 */
final class PdfPreview
{
    /**
     * $chaveFonte fica por compatibilidade com a tela do admin: o layout
     * do relatório usa sempre Montserrat e Nunito Sans.
     */
    public function gerar(?string $chaveFonte = null): string
    {
        $tmp = sys_get_temp_dir().'/mn-preview-'.uniqid('', true).'.pdf';
        try {
            RelatorioQuinzenalPdf::gerar(self::dadosDeExemplo(), $tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public static function dadosDeExemplo(): DadosRelatorioQuinzenal
    {
        $datas = [];
        for ($d = 16; $d <= 30; $d++) {
            $datas[] = sprintf('%02d/09', $d);
        }
        $questoes = ['16/09' => [86, 18], '18/09' => [31, 4], '25/09' => [34, 11], '27/09' => [65, 20], '29/09' => [38, 12]];
        $p = 'PMSC - ';
        $historico = [
            [$p.'Português', 'Compreensão e interpretação de textos', 'Estudo (coach)', '07:51:35'],
            [$p.'Português', 'Emprego das classes de palavras (menos verbo)', 'Estudo', '02:42:00'],
            [$p.'Português', 'Emprego/correlação de tempos e modos verbais', 'Estudo', '02:00:50'],
            [$p.'Direito Processual Penal', 'Título III: Ação penal', 'Exercício', '03:37:00'],
            [$p.'Direito Processual Penal', 'Título II: Inquérito policial (art. 4º ao 23)', 'Exercício', '02:15:00'],
            [$p.'Direito Processual Penal', 'Título III: Ação penal', 'Estudo', '02:05:30'],
            [$p.'Direito Penal Militar', 'Suspensão condicional da pena', 'Exercício', '02:30:00'],
            [$p.'Direito Penal Militar', 'Suspensão condicional da pena', 'Estudo', '02:10:00'],
            [$p.'Direito Penal Militar', 'Livramento condicional', 'Estudo', '01:20:00'],
            [$p.'Direito Penal Especial', 'Crimes contra o patrimônio: furto, roubo e extorsão (arts. 155 a 160)', 'Estudo', '04:20:00'],
            [$p.'Direito Penal Geral', 'Crime: fato típico', 'Estudo', '03:54:33'],
            [$p.'Legislação de Trânsito', 'Lei nº 9.503/1997: Dos veículos (art. 96 ao 117)', 'Estudo', '01:50:00'],
            [$p.'Legislação de Trânsito', 'Lei nº 9.503/1997: Dos veículos (art. 96 ao 117)', 'Exercício', '01:25:00'],
            [$p.'Legislação Especial', 'Estatuto do Idoso (Lei nº 10.741/2003)', 'Estudo', '02:05:47'],
            [$p.'Legislação Especial', 'Estatuto do Idoso (Lei nº 10.741/2003)', 'Estudo por vídeos', '00:45:07'],
            [$p.'Direito Constitucional', 'Direitos e deveres individuais e coletivos (art. 5º)', 'Estudo', '01:29:58'],
        ];

        return new DadosRelatorioQuinzenal(
            aluno: 'Mychel',
            curso: 'CFP · PMSC',
            inicio: new \DateTimeImmutable('2026-09-16'),
            fim: new \DateTimeImmutable('2026-09-30'),
            datas: $datas,
            planejado: [3, 3, 3, 5, 3.5, 3, 3, 3, 3, 3, 5, 3.5, 3, 3, 3],
            estudado: [2, 3, 3, 3, 3, 3.5, 2, 3, 2, 2.5, 2, 3.5, 3, 2, 2.3],
            brutas: [2, 3, 3, 3, 3, 3.5, 2, 3, 2, 2.5, 2, 3.5, 3, 2, 2.3],
            liquidas: [2.3, 3.2, 3.1, 4.3, 4.2, 3.4, 2.2, 3.3, 2.1, 2.8, 2.2, 2.4, 2.4, 2, 2.7],
            acertosDia: array_map(static fn (string $dia): float => (float) ($questoes[$dia][0] ?? 0), $datas),
            errosDia: array_map(static fn (string $dia): float => (float) ($questoes[$dia][1] ?? 0), $datas),
            acumulado: [
                ['label' => 'Total de horas (acumulado)', 'value' => '638:45'],
                ['label' => 'Dias de estudo', 'value' => '234'],
                ['label' => 'Acertos (geral)', 'value' => '80%'],
                ['label' => 'Progresso no plano de estudos', 'value' => '23%'],
            ],
            insights: [
                ['label' => 'MÉDIA DIÁRIA', 'value' => '02:39'],
                ['label' => 'MATÉRIA MAIS ESTUDADA', 'value' => $p.'Português'],
                ['label' => 'MATÉRIA MENOS ESTUDADA', 'value' => $p.'Direito Constitucional'],
            ],
            panorama: [
                ['label' => 'Questões realizadas', 'value' => '319'],
                ['label' => 'Acertos', 'value' => '254'],
                ['label' => 'Percentual de acertos', 'value' => '79,6%'],
            ],
            assuntos: [
                ['disciplina' => $p.'Direito Penal Militar', 'assunto' => 'Suspensão condicional da pena', 'taxa' => '85,0%'],
                ['disciplina' => $p.'Direito Processual Penal', 'assunto' => 'Título II: Inquérito policial (art. 4º ao 23)', 'taxa' => '82,7%'],
                ['disciplina' => $p.'Direito Processual Penal', 'assunto' => 'Título III: Ação penal', 'taxa' => '75,8%'],
                ['disciplina' => $p.'Legislação de Trânsito', 'assunto' => 'Lei nº 9.503/1997: Dos veículos (art. 96 ao 117)', 'taxa' => '75,0%'],
            ],
            historico: array_map(
                static fn (array $l): array => ['disciplina' => $l[0], 'assunto' => $l[1], 'modalidade' => $l[2], 'horas' => $l[3]],
                $historico
            ),
        );
    }
}
