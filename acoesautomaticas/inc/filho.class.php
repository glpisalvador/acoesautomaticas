<?php

/**
 * Plugin Ações Automáticas - chamados filhos de uma regra: modelos (aba "Chamados filhos") e criação
 * dos chamados vinculados ao chamado de origem.
 */
class PluginAcoesautomaticasFilho extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_acoesautomaticas_ticketsfilhos';

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Chamados filhos' : 'Chamado filho';
    }

    public static function canView(): bool
    {
        return PluginAcoesautomaticasRegra::canView();
    }

    public static function canCreate(): bool
    {
        return PluginAcoesautomaticasRegra::canUpdate();
    }

    public static function canUpdate(): bool
    {
        return PluginAcoesautomaticasRegra::canUpdate();
    }

    public static function canDelete(): bool
    {
        return PluginAcoesautomaticasRegra::canUpdate();
    }

    public static function canPurge(): bool
    {
        return PluginAcoesautomaticasRegra::canUpdate();
    }

    public static function daRegra(int $regraId): array
    {
        global $DB;
        if ($regraId <= 0) {
            return [];
        }
        return iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['regras_id' => $regraId], 'ORDER' => ['ordem ASC', 'id ASC']]), false);
    }

    /** Grava um modelo (novo se $id = 0). Retorna o id ou 0 */
    public static function salvar(int $regraId, int $id, array $in): int
    {
        global $DB;
        $nome = mb_substr(trim(strip_tags((string) ($in['nome'] ?? ''))), 0, 255);
        if ($nome === '') {
            Session::addMessageAfterRedirect('Informe o título do chamado filho.', false, ERROR);
            return 0;
        }
        $dados = [
            'nome'                 => $nome,
            'descricao'            => (string) ($in['descricao'] ?? ''),
            'itilcategories_id'    => max(0, (int) ($in['itilcategories_id'] ?? 0)),
            'groups_id_observador' => max(0, (int) ($in['groups_id_observador'] ?? 0)),
            'groups_id_tecnico'    => max(0, (int) ($in['groups_id_tecnico'] ?? 0)),
            'users_id_tecnico'     => max(0, (int) ($in['users_id_tecnico'] ?? 0)),
            'copiar_requerentes'   => empty($in['copiar_requerentes']) ? 0 : 1,
            'copiar_observadores'  => empty($in['copiar_observadores']) ? 0 : 1,
        ];
        if ($id > 0) {
            if (countElementsInTable(self::TABELA, ['id' => $id, 'regras_id' => $regraId]) === 0) {
                return 0;
            }
            $DB->update(self::TABELA, $dados, ['id' => $id]);
        } else {
            $dados['regras_id'] = $regraId;
            $dados['ordem'] = count(self::daRegra($regraId)) + 1;
            $DB->insert(self::TABELA, $dados);
            $id = (int) $DB->insertId();
        }
        Log::history($regraId, PluginAcoesautomaticasRegra::class, [0, '', 'Chamado filho salvo: ' . $nome], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return $id;
    }

    public static function excluir(int $regraId, int $id): bool
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id, 'regras_id' => $regraId], 'LIMIT' => 1]) as $r) {
            $DB->delete(self::TABELA, ['id' => $id]);
            Log::history($regraId, PluginAcoesautomaticasRegra::class, [0, '', 'Chamado filho removido: ' . $r['nome']], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
            return true;
        }
        return false;
    }

    /** Cria os chamados filhos do chamado de origem. Retorna quantos foram criados */
    public static function criar(Ticket $pai, array $regra): int
    {
        $modelos = self::daRegra((int) $regra['id']);
        if (!$modelos) {
            return 0;
        }
        $paiId = (int) $pai->getID();
        $atores = PluginAcoesautomaticasMotor::atores($paiId);
        $criados = 0;
        foreach ($modelos as $m) {
            $atoresNovos = [];
            if ((int) $m['copiar_requerentes']) {
                foreach (self::usuariosDoPai($paiId, CommonITILActor::REQUESTER) as $u) {
                    $atoresNovos['requester'][] = $u;
                }
            }
            if ((int) $m['copiar_observadores']) {
                foreach (self::usuariosDoPai($paiId, CommonITILActor::OBSERVER) as $u) {
                    $atoresNovos['observer'][] = $u;
                }
                foreach ($atores['grupos'][CommonITILActor::OBSERVER] as $g) {
                    $atoresNovos['observer'][] = ['itemtype' => 'Group', 'items_id' => $g];
                }
            }
            if ((int) $m['groups_id_observador'] > 0) {
                $atoresNovos['observer'][] = ['itemtype' => 'Group', 'items_id' => (int) $m['groups_id_observador']];
            }
            if ((int) $m['groups_id_tecnico'] > 0) {
                $atoresNovos['assign'][] = ['itemtype' => 'Group', 'items_id' => (int) $m['groups_id_tecnico']];
            }
            if ((int) $m['users_id_tecnico'] > 0) {
                $atoresNovos['assign'][] = ['itemtype' => 'User', 'items_id' => (int) $m['users_id_tecnico'], 'use_notification' => 1, 'alternative_email' => ''];
            }
            $dados = [
                'name'        => strip_tags(PluginAcoesautomaticasMotor::substituir((string) $m['nome'], $pai, $regra)),
                'content'     => trim(strip_tags((string) $m['descricao'], '<img>')) !== '' ? PluginAcoesautomaticasMotor::substituir((string) $m['descricao'], $pai, $regra)
                    : '<p>Chamado criado automaticamente a partir do chamado #' . $paiId . '.</p>',
                'entities_id' => (int) $pai->fields['entities_id'],
                'type'        => (int) $pai->fields['type'],
                'urgency'     => (int) $pai->fields['urgency'],
                'impact'      => (int) $pai->fields['impact'],
                'priority'    => (int) $pai->fields['priority'],
            ];
            $dados['name'] = html_entity_decode($dados['name'], ENT_QUOTES, 'UTF-8');
            if ((int) $m['itilcategories_id'] > 0) {
                $dados['itilcategories_id'] = (int) $m['itilcategories_id'];
            }
            if ($atoresNovos) {
                $dados['_actors'] = $atoresNovos;
            }
            $novo = new Ticket();
            $id = (int) $novo->add($dados);
            if ($id > 0) {
                // "filho de": tickets_id_1 é o filho e tickets_id_2 o pai
                (new Ticket_Ticket())->add(['tickets_id_1' => $id, 'tickets_id_2' => $paiId, 'link' => Ticket_Ticket::SON_OF]);
                $criados++;
            }
        }
        return $criados;
    }

    private static function usuariosDoPai(int $paiId, int $papel): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['users_id', 'alternative_email', 'use_notification'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $paiId, 'type' => $papel]]) as $r) {
            $lista[] = ['itemtype' => 'User', 'items_id' => (int) $r['users_id'], 'use_notification' => (int) $r['use_notification'], 'alternative_email' => (string) $r['alternative_email']];
        }
        return $lista;
    }

    // =====================================================================
    // Aba "Chamados filhos" da regra
    // =====================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginAcoesautomaticasRegra || $item->isNewItem()) {
            return '';
        }
        $n = !empty($_SESSION['glpishow_count_on_tabs']) ? count(self::daRegra((int) $item->getID())) : 0;
        return self::createTabEntry('Chamados filhos', $n, null, 'ti ti-git-fork');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof PluginAcoesautomaticasRegra) {
            self::mostrar($item);
        }
        return true;
    }

    private static function cartao(PluginAcoesautomaticasRegra $regra, array $m, bool $editar): string
    {
        $C = PluginAcoesautomaticasConfig::class;
        $e = [$C, 'e'];
        $id = (int) ($m['id'] ?? 0);
        $novo = $id === 0;
        $h = '<form method="post" action="' . $e($C::url('regra.form.php')) . '" class="card acoesautomaticas-card">';
        $h .= '<input type="hidden" name="id" value="' . (int) $regra->getID() . '"><input type="hidden" name="filho_id" value="' . $id . '">';
        $h .= '<div class="card-header"><h5><i class="ti ti-' . ($novo ? 'plus' : 'git-fork') . '"></i> ' . ($novo ? 'Novo chamado filho' : $e($m['nome'])) . '</h5></div><div class="card-body">';
        $campo = fn(string $rotulo, string $controle, string $classe = '') => '<div class="acoesautomaticas-campo ' . $classe . '"><label>' . $e($rotulo) . '</label>' . $controle . '</div>';
        $h .= '<div class="acoesautomaticas-grade">'
            . $campo('Título *', '<input type="text" class="form-control form-control-sm" name="nome" maxlength="255" required value="' . $e($m['nome'] ?? '') . '"' . ($editar ? '' : ' disabled') . '>', 'acoesautomaticas-campo-largo')
            . $campo('Categoria', (string) ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => (int) ($m['itilcategories_id'] ?? 0), 'display' => false, 'entity' => -1, 'width' => '100%', 'rand' => mt_rand()]))
            . $campo('Grupo técnico', (string) Group::dropdown(['name' => 'groups_id_tecnico', 'value' => (int) ($m['groups_id_tecnico'] ?? 0), 'display' => false, 'entity' => -1, 'condition' => ['is_assign' => 1], 'width' => '100%', 'rand' => mt_rand()]))
            . $campo('Técnico', (string) User::dropdown(['name' => 'users_id_tecnico', 'value' => (int) ($m['users_id_tecnico'] ?? 0), 'right' => 'own_ticket', 'display' => false, 'width' => '100%', 'rand' => mt_rand()]))
            . $campo('Grupo observador', (string) Group::dropdown(['name' => 'groups_id_observador', 'value' => (int) ($m['groups_id_observador'] ?? 0), 'display' => false, 'entity' => -1, 'condition' => ['is_watcher' => 1], 'width' => '100%', 'rand' => mt_rand()]))
            . '</div>';
        $h .= $campo('Descrição', (string) Html::textarea(['name' => 'descricao', 'value' => (string) ($m['descricao'] ?? ''), 'enable_richtext' => true, 'cols' => 100, 'rows' => 4, 'display' => false, 'rand' => mt_rand()]));
        $marcar = fn(string $n, bool $v, string $r) => '<input type="hidden" name="' . $n . '" value="0"><label class="acoesautomaticas-opcao"><input type="checkbox" class="acoesautomaticas-check" name="' . $n . '" value="1"' . ($v ? ' checked' : '') . '> ' . $r . '</label>';
        $h .= '<div class="acoesautomaticas-linha">' . $marcar('copiar_requerentes', $novo || (int) $m['copiar_requerentes'], 'Copiar os requerentes do chamado de origem')
            . $marcar('copiar_observadores', !$novo && (int) $m['copiar_observadores'], 'Copiar os observadores (usuários e grupos)') . '</div>';
        if ($editar) {
            $h .= '<div class="acoesautomaticas-rodape-form">'
                . (!$novo ? '<button type="submit" name="excluir_filho" value="1" class="btn btn-sm btn-ghost-danger" data-acoesautomaticas-confirmar="Remover este chamado filho?"><i class="ti ti-trash"></i><span>Remover</span></button>' : '<span></span>')
                . '<button type="submit" name="salvar_filho" value="1" class="btn btn-sm acoesautomaticas-btn-principal"><i class="ti ti-device-floppy"></i><span>' . ($novo ? 'Adicionar' : 'Salvar') . '</span></button></div>';
        }
        $h .= '</div>' . Html::closeForm(false);
        return $h;
    }

    public static function mostrar(PluginAcoesautomaticasRegra $regra): void
    {
        $C = PluginAcoesautomaticasConfig::class;
        echo $C::assets();
        $editar = PluginAcoesautomaticasRegra::canUpdate();
        $modelos = self::daRegra((int) $regra->getID());
        echo '<div class="acoesautomaticas-pagina">';
        echo '<p class="acoesautomaticas-explicacao"><i class="ti ti-info-circle"></i><span>Quando a ação <strong>Criar chamados filhos</strong> está ligada, cada modelo abaixo vira um chamado na mesma entidade, com tipo, urgência e prioridade do chamado de origem, vinculado como <em>filho</em> dele. '
            . 'Título e descrição aceitam as variáveis {id}, {titulo}, {requerente}, {entidade}, {categoria}, {status}, {link} e {regra}.</span></p>';
        if (!(int) $regra->fields['acao_tickets_filhos']) {
            echo '<div class="acoesautomaticas-alerta acoesautomaticas-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>A ação <strong>Criar chamados filhos</strong> está desligada nesta regra: os modelos não serão usados até ligá-la.</span></div>';
        }
        foreach ($modelos as $m) {
            echo self::cartao($regra, $m, $editar);
        }
        if (!$modelos && !$editar) {
            echo '<div class="acoesautomaticas-vazio"><i class="ti ti-git-fork"></i><span>Nenhum chamado filho configurado.</span></div>';
        }
        if ($editar) {
            echo self::cartao($regra, [], true);
        }
        echo '</div>';
    }
}
