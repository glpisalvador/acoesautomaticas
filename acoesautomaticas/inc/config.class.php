<?php

/**
 * Plugin Ações Automáticas - configurações (chave/valor) e utilitários comuns
 */
class PluginAcoesautomaticasConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_acoesautomaticas_configs';
    public const DIREITO = 'plugin_acoesautomaticas_regra';

    /** Variáveis aceitas nos textos das ações */
    public const VARIAVEIS = [
        '{id}'         => 'Número do chamado',
        '{titulo}'     => 'Título do chamado',
        '{requerente}' => 'Requerentes',
        '{entidade}'   => 'Entidade',
        '{categoria}'  => 'Categoria',
        '{status}'     => 'Status',
        '{link}'       => 'Endereço do chamado',
        '{regra}'      => 'Nome da regra',
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Ações automáticas';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'duplicados_ativo'             => '0',
            'duplicados_itilcategories_id' => '0',
            'duplicados_users_id'          => '0',
            'duplicados_texto_solucao'     => '<p>Chamado solucionado automaticamente: já existe um chamado em andamento com o mesmo título e descrição ({link}).</p>',
            'duplicados_mesma_entidade'    => '1',
            'duplicados_dias'              => '30',
            'duplicados_avisar_pai'        => '1',
            'logs_dias'                    => '90',
        ];
    }

    private static ?array $acoesautomaticasCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$acoesautomaticasCache === null) {
            self::$acoesautomaticasCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$acoesautomaticasCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$acoesautomaticasCache)) {
            return self::$acoesautomaticasCache[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$acoesautomaticasCache = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/acoesautomaticas/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/acoesautomaticas/' . $caminho . '?v=' . PLUGIN_ACOESAUTOMATICAS_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    public static function assets(): string
    {
        static $feito = false;
        if ($feito) {
            return '';
        }
        $feito = true;
        return '<link rel="stylesheet" href="' . self::e(self::urlAsset('css/acoesautomaticas.css')) . '">'
            . '<script src="' . self::e(self::urlAsset('js/acoesautomaticas.js')) . '"></script>'
            . self::filtroEntidades();
    }

    /** Seletores de entidade do GLPI mostram só as entidades de primeiro nível (as filhas entram por "subentidades") */
    public static function filtroEntidades(): string
    {
        global $DB;
        $ocultas = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ocultas[] = (int) $r['id'];
        }
        return '<script>window.acoesautomaticasEntidadesOcultas = ' . json_encode($ocultas) . ';</script>';
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Multiselect com pesquisa, marcar todos e selecionados primeiro (listas curtas e fixas) */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Qualquer'): string
    {
        $selecionados = array_map('strval', $selecionados);
        $marcados = [];
        $demais = [];
        foreach ($opcoes as $id => $rotulo) {
            if (in_array((string) $id, $selecionados, true)) {
                $marcados[$id] = $rotulo;
            } else {
                $demais[$id] = $rotulo;
            }
        }
        $h = '<div class="acoesautomaticas-ms" data-acoesautomaticas-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="">';
        $h .= '<button type="button" class="acoesautomaticas-ms-cabecalho form-select form-select-sm" data-acoesautomaticas-ms-abrir><span class="acoesautomaticas-ms-texto"></span></button>';
        $h .= '<div class="acoesautomaticas-ms-dropdown" hidden>';
        $h .= '<div class="acoesautomaticas-ms-topo"><input type="text" class="form-control form-control-sm acoesautomaticas-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="acoesautomaticas-ms-todos"><input type="checkbox" class="acoesautomaticas-check" data-acoesautomaticas-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="acoesautomaticas-ms-opcoes">';
        foreach ($marcados + $demais as $id => $rotulo) {
            $marcado = isset($marcados[$id]);
            $h .= '<label class="acoesautomaticas-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower((string) $rotulo)) . '" data-ordem="' . self::e(array_search($id, array_keys($opcoes), true)) . '">'
                . '<input type="checkbox" class="acoesautomaticas-check" name="' . self::e($name) . '[]" value="' . self::e($id) . '"' . ($marcado ? ' checked' : '') . '>'
                . '<span>' . self::e($rotulo) . '</span></label>';
        }
        $h .= '</div></div><div class="acoesautomaticas-ms-contador"></div></div>';
        return $h;
    }

    /** Lista de inteiros a partir de JSON ou array (ignora vazios) */
    public static function ids($valor, bool $aceitaZero = false): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }
        $ids = array_map('intval', array_filter((array) $valor, fn($v) => $v !== '' && $v !== null && is_numeric($v)));
        return array_values(array_unique(array_filter($ids, fn($v) => $aceitaZero ? $v >= 0 : $v > 0)));
    }
}
