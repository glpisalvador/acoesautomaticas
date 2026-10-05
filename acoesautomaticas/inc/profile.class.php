<?php

/**
 * Plugin Ações Automáticas - aba "Ações automáticas" no perfil (direitos nativos)
 */
class PluginAcoesautomaticasProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Ações automáticas';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginAcoesautomaticasRegra',
            'label'    => 'Regras automáticas',
            'field'    => PluginAcoesautomaticasConfig::DIREITO,
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry('Ações automáticas', 0, null, 'ti ti-robot');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginAcoesautomaticasConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $pode,
            'default_class' => 'tab_bg_2',
            'title'         => 'Ações automáticas',
        ]);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }
}
