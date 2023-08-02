<?php $this->pageTitle=Yii::app()->name; ?>

<p>Я взял первую попавшуюся демку из доки по Yii1 и работал с ее индексной стр, по возможности не меняя другие части демки. Т.к. упомянутую в ТЗ версию Yii 1.24 взять уже негде, я поставил ближайшую
    <a href="https://github.com/yiisoft-contrib/museum/tree/master/files" target="_blank">(список доступных архивных версий Yii1).</a>
</p>
<p>На этой странице будет показываться попап в соответствии с прописанными в ТЗ условиями. Для сокращения времени разработки применен подход MVP и не реализованы никакие вторичные функции.</p>

<h2>Таблица статистики показов</h2>
<?php
    $dataProvider=new CActiveDataProvider('bannerStat');
    $this->widget('zii.widgets.grid.CGridView', array(
        'dataProvider' => $dataProvider,
        'columns' => [
            array(
                'name'=>'bannerId',
                'footer'=>$total,
            ),
            array(
                'name'=>'showed',
                'footer'=>$total,
            ),
            array(
                'name'=>'clicked',
                'footer'=>$total,
            ),
            array(
                'name'=>'date',
                'footer'=>$total,
            ),
        ]
    ));
?>

<i class="small">Не хочешь ждать? В консоли подсказка.</i>
<div id="popup">
    <p id="content"></p>
    <hr>
    <button class="small" id="close" statId="" onclick="hideBanner()">Закрыть</button>
</div>
