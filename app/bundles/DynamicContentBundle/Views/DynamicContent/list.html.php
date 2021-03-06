<?php
/**
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
if ($tmpl == 'index') {
    $view->extend('MauticDynamicContentBundle:DynamicContent:index.html.php');
}
/* @var \Mautic\DynamicContentBundle\Entity\DynamicContent[] $items */
?>

<?php if (count($items)): ?>
    <div class="table-responsive page-list">
            <table class="table table-hover table-striped table-bordered dwctable-list" id="dwcTable">
                <thead>
                <tr>
                    <?php
                    echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                        'checkall' => 'true',
                        'target'   => '#dwcTable',
                    ]);

                    echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                        'sessionVar' => 'dynamicContent',
                        'orderBy'    => 'e.title',
                        'text'       => 'mautic.core.title',
                        'class'      => 'col-dwc-title',
                        'default'    => true,
                    ]);

                    echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                        'sessionVar' => 'dynamicContent',
                        'orderBy'    => 'e.name',
                        'text'       => 'mautic.core.category',
                        'class'      => 'visible-md visible-lg col-dwc-category',
                    ]);

                    echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                        'sessionVar' => 'dynamicContent',
                        'orderBy'    => 'e.id',
                        'text'       => 'mautic.core.id',
                        'class'      => 'col-dwc-id visible-md visible-lg',
                    ]);
                    ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?php
                            echo $view->render('MauticCoreBundle:Helper:list_actions.html.php', [
                                'item'            => $item,
                                'templateButtons' => [
                                    'edit'   => $view['security']->hasEntityAccess($permissions['dynamicContent:dynamicContents:editown'], $permissions['dynamicContent:dynamicContents:editother'], $item->getCreatedBy()),
                                    'clone'  => $permissions['dynamicContent:dynamicContents:create'],
                                    'delete' => $view['security']->hasEntityAccess($permissions['dynamicContent:dynamicContents:deleteown'], $permissions['dynamicContent:dynamicContents:deleteother'], $item->getCreatedBy()),
                                ],
                                'routeBase'  => 'dynamicContent',
                                'nameGetter' => 'getName',
                            ]);
                            ?>
                        </td>
                        <td>
                            <?php echo $view->render('MauticCoreBundle:Helper:publishstatus_icon.html.php', ['item' => $item, 'model' => 'dynamicContent']); ?>
                            <a href="<?php echo $view['router']->generate('mautic_dynamicContent_action', ['objectAction' => 'view', 'objectId' => $item->getId()]); ?>" data-toggle="ajax">
                                <?php echo $item->getName(); ?>
                                <?php
                                $hasVariants     = $item->isVariant();
                                $hasTranslations = $item->isTranslation();

                                if ($hasVariants || $hasTranslations): ?>
                                    <span>
                                <?php if ($hasVariants): ?>
                                    <span data-toggle="tooltip" title="<?php echo $view['translator']->trans('mautic.core.icon_tooltip.ab_test'); ?>">
                                            <i class="fa fa-fw fa-sitemap"></i>
                                        </span>
                                <?php endif; ?>
                                        <?php if ($hasTranslations): ?>
                                            <span data-toggle="tooltip" title="<?php echo $view['translator']->trans('mautic.core.icon_tooltip.translation'); ?>">
                                            <i class="fa fa-fw fa-language"></i>
                                        </span>
                                        <?php endif; ?>
                                 </span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td class="visible-md visible-lg">
                            <?php $category = $item->getCategory(); ?>
                            <?php $catName  = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
                            <?php $color    = ($category) ? '#'.$category->getColor() : 'inherit'; ?>
                            <span style="white-space: nowrap;"><span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span></span>
                        </td>
                        <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <div class="panel-footer">
        <?php echo $view->render('MauticCoreBundle:Helper:pagination.html.php', [
            'totalItems' => count($items),
            'page'       => $page,
            'limit'      => $limit,
            'menuLinkId' => 'mautic_dynamicContent_index',
            'baseUrl'    => $view['router']->generate('mautic_dynamicContent_index'),
            'sessionVar' => 'dynamicContent',
        ]); ?>
        </div>
    </div>
<?php else: ?>
    <?php echo $view->render('MauticCoreBundle:Helper:noresults.html.php'); ?>
<?php endif; ?>
