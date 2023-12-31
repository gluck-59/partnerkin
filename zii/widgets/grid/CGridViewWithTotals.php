<?php

    Yii::import('zii.widgets.grid.CGridView');

    class CGridViewWithTotals extends CGridView
    {
        public function renderTableHeader()
        {
            if(!$this->hideHeader)
            {
                echo "<thead>\n";

                if($this->filterPosition===self::FILTER_POS_HEADER)
                    $this->renderFilter();

                echo "<tr>\n";
                foreach($this->columns as $column)
                    $column->renderHeaderCell();
                echo "</tr>\n";

                if($this->filterPosition===self::FILTER_POS_BODY)
                    $this->renderFilter();

                if($this->getHasFooter())
                {
                    echo "<tr>\n";
                    foreach($this->columns as $column)
                        $column->renderFooterCell();
                    echo "</tr>\n";
                }

                echo "</thead>\n";
            }
            else if($this->filter!==null && ($this->filterPosition===self::FILTER_POS_HEADER || $this->filterPosition===self::FILTER_POS_BODY))
            {
                echo "<thead>\n";
                $this->renderFilter();
                echo "</thead>\n";
            }
        }
    }