<?php

class SiteController extends Controller
{
	/**
	 * Declares class-based actions.
	 */
	public function actions()
	{
		return array(
			// captcha action renders the CAPTCHA image displayed on the contact page
			'captcha'=>array(
				'class'=>'CCaptchaAction',
				'backColor'=>0xFFFFFF,
			),
			// page action renders "static" pages stored under 'protected/views/site/pages'
			// They can be accessed via: index.php?r=site/page&view=FileName
			'page'=>array(
				'class'=>'CViewAction',
			),
		);
	}


	/**
	 * This is the default 'index' action that is invoked
	 * when an action is not explicitly requested by users.
	 */
	public function actionIndex()
	{
        $model = new BannerStat();
        BannerStat::model()->findBySql('select * from bannerStat');

        $this->render('index',array(
            'model'=>$model,
        ));
	}


    /**
     * получает содержимое попапа
     * @return void
     */
    public function actionGetBanner() {
        $bannerId = Yii::app()->request->getPost('bannerId');
        $modelBanner = new Banner();
        $banner = $modelBanner->find('id = '.$bannerId)->getAttributes();

        $modelStat = new BannerStat();
        $date = new DateTime();
        $date = $date->format('Y-m-d');

        $stat = $modelStat->findByAttributes(['bannerId' => $bannerId, 'date' => $date]);
        $banner['statId'] = (is_null($stat) ? 0 : $stat->getAttributes()['id']);

        if (is_null($stat)) {       // create
            $modelStat->bannerId = $bannerId;
            $modelStat->showed = 1;
            $modelStat->date = $date;
            $modelStat->save();
        } else {                    // update
            // я так и не смог найти почему в Yii1 не работает model->update с обновленными атрибутами или model->saveAttributes
            // скорее всего вы давно это обошли и можно будет посмотреть у вас
            Yii::app()->db->createCommand('UPDATE bannerStat SET showed = :sh +1 WHERE id = :id')->query(array(':id' => $stat['id'], 'sh' => $stat['showed']));
        }
        echo json_encode($banner);
    }


    /**
     * пишет статистику кнопки "закрыть"
     * @return void
     */
    public function actionBannerStat() {
        $statId = Yii::app()->request->getPost('statId');
        $modelStat = new BannerStat();
        $stat = $modelStat->findByAttributes(['id' => $statId]);

        Yii::app()->db->createCommand('UPDATE bannerStat SET clicked = :clicked +1 WHERE id = :statId')->query(array(':statId' => $statId, ':clicked' => (is_null($stat) ? 1 :$stat->getAttributes()['clicked'])));
    }



	/**
	 * This is the action to handle external exceptions.
	 */
	public function actionError()
	{
	    if($error=Yii::app()->errorHandler->error)
	    {
	    	if(Yii::app()->request->isAjaxRequest)
	    		echo $error['message'];
	    	else
	        	$this->render('error', $error);
	    }
	}


}