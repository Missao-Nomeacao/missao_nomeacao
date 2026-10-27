#!/usr/bin/env php
<?php

/**
 * Gera o Relatório consolidado do Coach no Tutory para os alunos ATIVOS
 * (seções dos modelos do menu do mentor) e envia um único PDF por e-mail.
 *
 * CLI/HTTP puro — sem Selenium, sem Firefox, sem php-webdriver.
 *
 * Uso:
 *   php scripts/baixar_relatorios_tutory.php --periodo=1
 *   php scripts/baixar_relatorios_tutory.php --periodo=2 --teste
 *
 * Credenciais e pastas vêm do .env (veja .env.example / docs/tutory-relatorios.md).
 */

declare(strict_types=1);

use App\Services\Tutory\CoachReportDownloader;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$options = getopt('', ['periodo:', 'teste', 'help']);

if (isset($options['help']) || ! isset($options['periodo'])) {
    fwrite(STDERR, <<<'HELP'
Gera o relatório consolidado do Coach no Tutory para alunos ativos (CLI/HTTP).

Uso:
  php scripts/baixar_relatorios_tutory.php --periodo=1
  php scripts/baixar_relatorios_tutory.php --periodo=2 --teste

Opções:
  --periodo=1|2   Obrigatório.
                  1 = Dia inicial: 01 / Dia final: 15
                  2 = Dia inicial: 16 / Dia final: último dia do mês
  --teste         Baixa só os relatórios do aluno Mychel
  --help          Mostra esta ajuda

HELP);
    exit(isset($options['help']) ? 0 : 1);
}

$periodo = (string) $options['periodo'];
if (! in_array($periodo, ['1', '2'], true)) {
    fwrite(STDERR, "Erro: --periodo deve ser 1 ou 2.\n");
    exit(1);
}

$teste = array_key_exists('teste', $options);

try {
    $downloader = new CoachReportDownloader(
        periodo: $periodo,
        teste: $teste,
        logger: static function (string $message): void {
            echo $message.PHP_EOL;
        },
    );
    exit($downloader->run());
} catch (Throwable $exc) {
    fwrite(STDERR, 'ERRO FATAL: '.$exc->getMessage().PHP_EOL);
    exit(1);
}
