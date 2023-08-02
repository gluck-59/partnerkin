/**
 * решает нужно ли показывать попап и какой
 * забирает текст попапа с сервера
 * @param times
 */
function needShow(times) {
    let bannerData;
    if (times == 1) {
        console.log('ПОКАЗАТЬ ЧЕРЕЗ 5с')
        $.post( "/index.php?r=site/getBanner", { bannerId: 1 }, function( data ) {
            bannerData = data;
        }, "json");

        setTimeout(function () {
            showBanner(bannerData);
        }, 5000)
    } else if (times%3 == 0) {
        console.log('ПОКАЗАТЬ ЧЕРЕЗ 10с')

        $.post( "/index.php?r=site/getBanner", { bannerId: 2 }, function( data ) {
            bannerData = data;
        }, "json");

        setTimeout(function () {
            showBanner(bannerData);
        }, 10000)
    }
}


/**
 * показывает баннер
 * @param bannerData
 */
function showBanner(bannerData) {
    $('#popup #content').html(bannerData.text);
    $('#popup #close').attr('statId', bannerData.statId);
    $('#popup').show(300)
}


/**
 * скрвыает баннер, отправляет статистику кликов
 */
function hideBanner() {
    $.post( "/index.php?r=site/bannerStat", { statId: $('#popup #close').attr('statId') }, function( data ) { }, "json");
    $('#popup').hide(300);
}



window.onload = function() {
    // прежде всего нужно понять, сколько раз мы сегодня заходили
    let today = new String(new Date().getDate().toString() + new Date().getMonth().toString());
    let todayVisits = localStorage.getItem(today);

    // необходимо обработать вариант "я тут не был"
    if (!todayVisits) {
        todayVisits = 1;
        localStorage.setItem(today, 1);
    }

    // отдадим счетчик заходов методу показа и он разберется с ним самостоятельно
    needShow(todayVisits);

    // и в конце инкрементим счетчик
    localStorage.setItem(today, parseInt(localStorage.getItem(today)) + 1)
}

