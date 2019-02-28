<?php
class API
{

    public static function test()
    {
        $rand = rand();
        $rand = bland();
        echo date();
        echo date($foo);
        echo date($foo, $bar);
        echo date($foo, $bar, $baz);
        $feedPubDate = date('D, d M Y H:i:s O', Keyword::getValue('page:published-date#timestamp:'.$rootNode));
        echo date(foo($foo, $bar, baz($foo, $bar)));
        echo date(array(1,2,3));
        mktime(...$options);
        $fn = function() {rand();};

    }

    public static function test2()
    {
        Cache::noCache();
        $rand = rand();
        $fn   = function() {Cache::noCache();rand();};
        $fn   = function() {rand();};
    }

}//end class