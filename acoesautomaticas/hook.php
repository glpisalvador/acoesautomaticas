<?php

/**
 * Plugin Ações Automáticas - instalação (criação, migração da 1.x, direitos e tarefa automática)
 */

function plugin_acoesautomaticas_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $coluna = function (string $tabela, string $nome, string $definicao) use ($DB): void {
        if (!$DB->fieldExists($tabela, $nome, false)) {
            $DB->doQuery("ALTER TABLE `$tabela` ADD COLUMN `$nome` $definicao");
        }
    };

    // ------------------------------------------------------------ configurações
    if (!$DB->tableExists('glpi_plugin_acoesautomaticas_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_acoesautomaticas_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    // Perfis liberados na 1.x (viram direito nativo abaixo)
    $perfisAntigos = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_acoesautomaticas_configs', 'WHERE' => ['name' => 'allowed_profiles']]) as $r) {
        $perfisAntigos = PluginAcoesautomaticasConfig::ids((string) $r['value']);
    }
    foreach (PluginAcoesautomaticasConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_acoesautomaticas_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_acoesautomaticas_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : $valor]);
        }
    }

    // ------------------------------------------------------------ regras
    $R = 'glpi_plugin_acoesautomaticas_regras';
    if (!$DB->tableExists($R)) {
        $DB->doQuery("CREATE TABLE `$R` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $opcoes");
    }
    $colunasRegra = [
        'comment'                         => 'longtext NULL',
        'is_active'                       => 'tinyint(1) NOT NULL DEFAULT 1',
        'ordem'                           => 'int unsigned NOT NULL DEFAULT 0',
        'executar_em'                     => 'tinyint(1) NOT NULL DEFAULT 0',
        'tipos_atualizacao'               => 'text NULL',
        'parar_apos_executar'             => 'tinyint(1) NOT NULL DEFAULT 0',
        'contador_execucoes'              => 'int unsigned NOT NULL DEFAULT 0',
        'ultima_execucao'                 => 'timestamp NULL DEFAULT NULL',
        'range_tipo'                      => 'tinyint(1) NOT NULL DEFAULT 0',
        'range_hora_inicio'               => 'time NULL DEFAULT NULL',
        'range_hora_fim'                  => 'time NULL DEFAULT NULL',
        'range_data_inicio'               => 'datetime NULL DEFAULT NULL',
        'range_data_fim'                  => 'datetime NULL DEFAULT NULL',
        // Condições (listas em JSON; vazio = qualquer)
        'cond_tipos'                      => 'text NULL',
        'cond_status'                     => 'text NULL',
        'cond_entidades'                  => 'text NULL',
        'cond_subentidades'               => 'tinyint(1) NOT NULL DEFAULT 1',
        'cond_requerentes'                => 'text NULL',
        'cond_grupos_observadores'        => 'text NULL',
        'cond_grupos_atribuidos'          => 'text NULL',
        'cond_categorias'                 => 'text NULL',
        'cond_prioridades'                => 'text NULL',
        'cond_urgencias'                  => 'text NULL',
        'cond_origens'                    => 'text NULL',
        'cond_sem_tecnico'                => 'tinyint(1) NOT NULL DEFAULT 0',
        'titulo_contem'                   => 'varchar(255) NULL DEFAULT NULL',
        'titulo_nao_contem'               => 'varchar(255) NULL DEFAULT NULL',
        'descricao_contem'                => 'text NULL',
        'followup_contem'                 => 'text NULL',
        // Ações
        'acao_atender'                    => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_atender_users_id'           => 'int unsigned NOT NULL DEFAULT 0',
        'acao_atender_itilcategories_id'  => 'int unsigned NOT NULL DEFAULT 0',
        'acao_atender_trocar_observador'  => 'tinyint(1) NOT NULL DEFAULT 1',
        'acao_categorizar'                => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_categorizar_itilcategories_id' => 'int unsigned NOT NULL DEFAULT 0',
        'acao_grupo_tecnico'              => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_grupo_tecnico_groups_id'    => 'int unsigned NOT NULL DEFAULT 0',
        'acao_observador'                 => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_observador_users_id'        => 'int unsigned NOT NULL DEFAULT 0',
        'acao_grupo_observador'           => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_grupo_observador_groups_id' => 'int unsigned NOT NULL DEFAULT 0',
        'acao_prioridade'                 => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_prioridade_valor'           => 'tinyint NOT NULL DEFAULT 3',
        'acao_urgencia'                   => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_urgencia_valor'             => 'tinyint NOT NULL DEFAULT 3',
        'acao_followup'                   => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_followup_texto'             => 'longtext NULL',
        'acao_followup_privado'           => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_status'                     => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_status_valor'               => 'int NOT NULL DEFAULT 2',
        'acao_validacao'                  => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_validacao_users_id'         => 'int unsigned NOT NULL DEFAULT 0',
        'acao_validacao_texto'            => 'longtext NULL',
        'acao_tickets_filhos'             => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_pendente'                   => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_pendente_users_id'          => 'int unsigned NOT NULL DEFAULT 0',
        'acao_pendente_pendingreasons_id' => 'int unsigned NOT NULL DEFAULT 0',
        'acao_pendente_followup_texto'    => 'longtext NULL',
        'acao_solucionar'                 => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_solucionar_users_id'        => 'int unsigned NOT NULL DEFAULT 0',
        'acao_solucionar_itilcategories_id' => 'int unsigned NOT NULL DEFAULT 0',
        'acao_solucionar_texto'           => 'longtext NULL',
        'acao_excluir'                    => 'tinyint(1) NOT NULL DEFAULT 0',
        'acao_excluir_purgar'             => 'tinyint(1) NOT NULL DEFAULT 0',
    ];
    foreach ($colunasRegra as $nome => $def) {
        $coluna($R, $nome, $def);
    }
    foreach (['is_active', 'ordem'] as $indice) {
        if ($DB->numrows($DB->doQuery("SHOW INDEX FROM `$R` WHERE Key_name = " . $DB->quote($indice))) === 0) {
            $DB->doQuery("ALTER TABLE `$R` ADD KEY `$indice` (`$indice`)");
        }
    }

    // Migração da 1.x: condições de um valor só viram listas; "entities_id" sai do nome para o GLPI
    // não tratar a regra como item de uma entidade
    $legado = $DB->fieldExists($R, 'entities_id', false);
    if ($legado || $DB->fieldExists($R, 'tipo_ticket', false)) {
        foreach ($DB->request(['FROM' => $R]) as $r) {
            $mudar = [];
            $par = [
                'cond_tipos'               => 'tipo_ticket',
                'cond_status'              => 'status_ticket',
                'cond_entidades'           => $legado ? 'entities_id' : 'cond_entidade_antiga',
                'cond_requerentes'         => 'users_id_requerente',
                'cond_grupos_observadores' => 'groups_id_observador',
            ];
            foreach ($par as $novo => $antigo) {
                if (($r[$novo] ?? null) === null && (int) ($r[$antigo] ?? 0) > 0) {
                    $mudar[$novo] = json_encode([(int) $r[$antigo]]);
                }
            }
            if ($mudar) {
                $DB->update($R, $mudar, ['id' => (int) $r['id']]);
            }
        }
        if ($legado) {
            $DB->doQuery("ALTER TABLE `$R` CHANGE `entities_id` `cond_entidade_antiga` int unsigned NOT NULL DEFAULT 0");
        }
    }

    // ------------------------------------------------------------ chamados filhos
    $F = 'glpi_plugin_acoesautomaticas_ticketsfilhos';
    if (!$DB->tableExists($F)) {
        $DB->doQuery("CREATE TABLE `$F` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `regras_id` int unsigned NOT NULL DEFAULT 0,
            `nome` varchar(255) NOT NULL DEFAULT '',
            `descricao` longtext NULL,
            `itilcategories_id` int unsigned NOT NULL DEFAULT 0,
            `groups_id_observador` int unsigned NOT NULL DEFAULT 0,
            `ordem` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `regras_id` (`regras_id`)
        ) $opcoes");
    }
    $coluna($F, 'groups_id_tecnico', 'int unsigned NOT NULL DEFAULT 0');
    $coluna($F, 'users_id_tecnico', 'int unsigned NOT NULL DEFAULT 0');
    $coluna($F, 'copiar_requerentes', 'tinyint(1) NOT NULL DEFAULT 1');
    $coluna($F, 'copiar_observadores', 'tinyint(1) NOT NULL DEFAULT 0');

    // ------------------------------------------------------------ execuções
    $L = 'glpi_plugin_acoesautomaticas_logs';
    if (!$DB->tableExists($L)) {
        $DB->doQuery("CREATE TABLE `$L` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo` varchar(50) NOT NULL DEFAULT 'execucao',
            `regras_id` int unsigned NOT NULL DEFAULT 0,
            `regra_nome` varchar(255) NOT NULL DEFAULT '',
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `ticket_nome` varchar(255) NOT NULL DEFAULT '',
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `usuario_nome` varchar(255) NOT NULL DEFAULT '',
            `evento` varchar(100) NOT NULL DEFAULT '',
            `detalhes` longtext NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tipo` (`tipo`),
            KEY `regras_id` (`regras_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }
    $coluna($L, 'sucesso', 'tinyint(1) NOT NULL DEFAULT 1');

    // ------------------------------------------------------------ direito nativo
    $direito = PluginAcoesautomaticasConfig::DIREITO;
    if (count($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito], 'LIMIT' => 1])) === 0) {
        ProfileRight::addProfileRights([$direito]);
        $perfis = $perfisAntigos;
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE) {
                $perfis[] = (int) $r['profiles_id'];
            }
        }
        if ($perfis) {
            $DB->update('glpi_profilerights', ['rights' => READ | CREATE | UPDATE | DELETE | PURGE], ['name' => $direito, 'profiles_id' => array_values(array_unique($perfis))]);
        }
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito, 'profiles_id' => (int) $_SESSION['glpiactiveprofile']['id']]]) as $r) {
                $_SESSION['glpiactiveprofile'][$direito] = (int) $r['rights'];
            }
        }
    }

    // ------------------------------------------------------------ tarefa automática
    CronTask::register('PluginAcoesautomaticasLog', 'AcoesautomaticasManutencao', HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Ações automáticas: desliga regras com período encerrado e apaga execuções antigas',
    ]);

    return true;
}

function plugin_acoesautomaticas_uninstall(): bool
{
    // Regra do projeto: tabelas e direitos ficam (reinstalar recupera as regras); só a tarefa automática sai
    CronTask::unregister('acoesautomaticas');
    return true;
}
