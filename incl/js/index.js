$.messageService = function(method, params, callback, error_handler){
    $.post("message_service.php",
    {
        request:JSON.stringify({"method":method, "params":params, "id":Math.random()})
    }, function(obj){ 
        if (obj.error){
            error_handler ? error_handler(obj.error) : $('#errors').text(obj.error.message);
        } else{
            callback(obj.result);
        }
    }, "json");
};

$( function(){
    var $container = $('div.container');
    $.messageService("getCatalogues", [], function(data){
        console.log(data);
        for (var i = 0; i<data.length; ++i){
            var html = ''
                + '<div id="catalogue"><a href="edit.html?cat_id='
                + data[i].id + '" class="catalogue">' 
                + data[i].name + '</a></div>'
                + ''
                + '<div id="progress-bar" class="progress-bar green stripes">'
                + '<span style="width:'
                + parseInt(data[i].translated_count / data[i].message_count *100)
                + '%"></span>'
                + '</div>'
                + '<div id="percentage"><p class="percentage">'
                + data[i].translated_count + '/' + data[i].message_count +'</p></div>';
            $container.append(html);
        };
    });
});

