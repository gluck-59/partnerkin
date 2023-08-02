<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="ru">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="language" content="ru" />

	<!-- blueprint CSS framework -->
	<link rel="stylesheet" type="text/css" href="<?php echo Yii::app()->request->baseUrl; ?>/css/screen.css" media="screen, projection" />
	<link rel="stylesheet" type="text/css" href="<?php echo Yii::app()->request->baseUrl; ?>/css/main.css" />
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
    <script src="/js/main.js"></script>

	<title><?php echo CHtml::encode($this->pageTitle); ?></title>
</head>

<body>
    <div class="container" id="page">
        <div id="header">
            <div id="logo"><?php echo CHtml::encode(Yii::app()->name); ?></div>
        </div><!-- header -->

        <?php
            echo $content;
        ?>

        <div id="footer">
            <?php echo 'Yii'.Yii::getVersion(); ?>
        </div><!-- footer -->

    </div><!-- page -->
</body>
</html>