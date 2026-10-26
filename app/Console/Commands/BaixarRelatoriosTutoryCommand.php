<?php

namespace App\Console\Commands;

use App\Services\Tutory\CoachReportDownloader;
use App\Services\Tutory\TutoryRelatorioAgenda;
use Illuminate\Console\Command;
use Throwable;

class BaixarRelatoriosTutoryCommand extends Command
{
    protected $signature = 'tutory:baixar-relatorios
                            {--periodo= : 1 = dias 01–15; 2 = dia 16 até o último dia do mês}
                            {--teste : Baixa só o relatório do aluno Mychel}
                            {--se-pendente : Não envia de novo se este período do mês já foi concluído}';

    protected $description = 'Gera o relatório consolidado do Coach com PHP/Dompdf (sem npm/Node) e envia por e-mail';

    public function handle(): int
    {
        $periodo = (string) $this->option('periodo');
        if (! in_array($periodo, ['1', '2'], true)) {
            $this->error('Informe --periodo=1 ou --periodo=2.');
            $this->line('  1 = Dia inicial: 01 / Dia final: 15');
            $this->line('  2 = Dia inicial: 16 / Dia final: último dia do mês');

            return self::FAILURE;
        }

        $teste = (bool) $this->option('teste');
        $sePendente = (bool) $this->option('se-pendente');
        $agenda = new TutoryRelatorioAgenda;
        $chave = TutoryRelatorioAgenda::chaveEnvio($periodo);

        $this->line('Agora: '.now()->timezone(config('app.timezone'))->toDateTimeString().' ('.config('app.timezone').')');
        $this->line('Chave de envio: '.$chave);

        if ($sePendente && ! $teste && ! $agenda->deveExecutar($periodo)) {
            $this->warn('Período já enviado ou em andamento ('.$chave.'). Use sem --se-pendente para forçar.');

            return self::SUCCESS;
        }

        if ($teste) {
            $this->warn('Modo --teste ativo: processa apenas Mychel.');
        }
        $this->line('PDF com PHP/Dompdf — não usa npm/Node. Ignore "npm: command not found".');

        if ($sePendente && ! $teste) {
            $agenda->marcarInicio($periodo);
        }

        try {
            $downloader = new CoachReportDownloader(
                periodo: $periodo,
                teste: $teste,
                logger: function (string $message): void {
                    $this->line($message);
                },
            );

            $codigo = $downloader->run();
            if ($codigo === 0 && $sePendente && ! $teste) {
                $agenda->marcarConcluido($periodo);
            }

            return $codigo;
        } catch (Throwable $exc) {
            $this->error($exc->getMessage());

            return self::FAILURE;
        }
    }
}
