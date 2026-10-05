<?php

/**
 * Plugin Ações Automáticas - item no menu Ferramentas (submenus: regras, execuções, configuração)
 */
class PluginAcoesautomaticasMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Ações automáticas';
    }

    public static function getMenuName(): string
    {
        return 'Ações automáticas';
    }

    public static function getIcon(): string
    {
        return 'ti ti-robot';
    }

    public static function canView(): bool
    {
        return PluginAcoesautomaticasRegra::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        $C = PluginAcoesautomaticasConfig::class;
        if (!self::canView()) {
            return false;
        }
        $lista = $C::url('regra.php');
        $regras = ['title' => 'Regras', 'page' => $lista, 'icon' => 'ti ti-robot', 'links' => ['search' => $lista]];
        if (PluginAcoesautomaticasRegra::canCreate()) {
            $regras['links']['add'] = $C::url('regra.form.php');
        }
        $opcoes = [
            'regra' => $regras,
            'log'   => ['title' => 'Execuções', 'page' => $C::url('log.php'), 'icon' => 'ti ti-history', 'links' => ['search' => $C::url('log.php')]],
        ];
        if ($C::ehAdmin()) {
            $opcoes['config'] = ['title' => 'Configuração', 'page' => $C::url('config.form.php'), 'icon' => 'ti ti-settings'];
        }
        return [
            'title'   => self::getMenuName(),
            'page'    => $lista,
            'icon'    => self::getIcon(),
            'links'   => $regras['links'],
            'options' => $opcoes,
        ];
    }

    /** Cabeçalho nativo com breadcrumb e abas das páginas do plugin */
    public static function cabecalho(string $chave, string $titulo): void
    {
        $C = PluginAcoesautomaticasConfig::class;
        Html::header($titulo, $_SERVER['PHP_SELF'] ?? '', 'tools', self::class, $chave);
        $abas = ['regra' => ['ti ti-robot', 'Regras', 'regra.php'], 'log' => ['ti ti-history', 'Execuções', 'log.php']];
        if ($C::ehAdmin()) {
            $abas['config'] = ['ti ti-settings', 'Configuração', 'config.form.php'];
        }
        echo '<ul class="nav nav-tabs acoesautomaticas-modulos">';
        foreach ($abas as $k => [$icone, $rotulo, $pagina]) {
            echo '<li class="nav-item"><a class="nav-link' . ($k === $chave ? ' active' : '') . '" href="' . $C::e($C::url($pagina)) . '"><i class="' . $icone . '"></i> ' . $C::e($rotulo) . '</a></li>';
        }
        echo '</ul>';
    }
}
