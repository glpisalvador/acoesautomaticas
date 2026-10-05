<?php

/**
 * Plugin Ações Automáticas - GLPI 11 e 12
 * Regras que agem sozinhas nos chamados quando eles são criados ou mudam: atribuir, categorizar,
 * acompanhar, mudar status, solucionar, deixar pendente, pedir validação, criar filhos e mais.
 * Inclui teste de regra, execução manual em lotes, solução automática de duplicados e registro das execuções.
 */

define('PLUGIN_ACOESAUTOMATICAS_VERSION', '2.0.0');
define('PLUGIN_ACOESAUTOMATICAS_MIN_GLPI', '11.0.0');
define('PLUGIN_ACOESAUTOMATICAS_MAX_GLPI', '12.99.99');

function plugin_init_acoesautomaticas(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['acoesautomaticas'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('acoesautomaticas')) {
        return;
    }

    // Nomes de tabela em português (o GLPI deduziria "..._filhos" e "..._logs" a partir das classes)
    foreach ([
        'PluginAcoesautomaticasRegra' => 'glpi_plugin_acoesautomaticas_regras',
        'PluginAcoesautomaticasFilho' => 'glpi_plugin_acoesautomaticas_ticketsfilhos',
        'PluginAcoesautomaticasLog'   => 'glpi_plugin_acoesautomaticas_logs',
    ] as $classe => $tabela) {
        $CFG_GLPI['glpitablesitemtype'][$classe] = $tabela;
        $CFG_GLPI['glpiitemtypetables'][$tabela] = $classe;
    }

    Plugin::registerClass('PluginAcoesautomaticasRegra');
    Plugin::registerClass('PluginAcoesautomaticasFilho');
    Plugin::registerClass('PluginAcoesautomaticasLog');
    Plugin::registerClass('PluginAcoesautomaticasMenu');
    Plugin::registerClass('PluginAcoesautomaticasProfile', ['addtabon' => ['Profile']]);

    $PLUGIN_HOOKS['config_page']['acoesautomaticas'] = 'front/config.form.php';
    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['menu_toadd']['acoesautomaticas'] = ['tools' => 'PluginAcoesautomaticasMenu'];
    }

    // Eventos dos chamados
    $M = 'PluginAcoesautomaticasMotor';
    $PLUGIN_HOOKS['pre_item_add']['acoesautomaticas'] = ['Ticket' => [$M, 'antesDeCriar']];
    $PLUGIN_HOOKS['item_add']['acoesautomaticas'] = [
        'Ticket'       => [$M, 'aoCriarChamado'],
        'ITILFollowup' => [$M, 'aoAdicionarAcompanhamento'],
        'TicketTask'   => [$M, 'aoAdicionarTarefa'],
        'ITILSolution' => [$M, 'aoAdicionarSolucao'],
        'Ticket_User'  => [$M, 'aoAdicionarUsuario'],
        'Group_Ticket' => [$M, 'aoAdicionarGrupo'],
    ];
    $PLUGIN_HOOKS['item_update']['acoesautomaticas'] = ['Ticket' => [$M, 'aoAtualizarChamado']];
}

function plugin_version_acoesautomaticas(): array
{
    return [
        'name'         => 'Ações Automáticas',
        'version'      => PLUGIN_ACOESAUTOMATICAS_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ACOESAUTOMATICAS_MIN_GLPI,
                'max' => PLUGIN_ACOESAUTOMATICAS_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_acoesautomaticas_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_ACOESAUTOMATICAS_MIN_GLPI, '>=');
}

function plugin_acoesautomaticas_check_config($verbose = false): bool
{
    return true;
}
