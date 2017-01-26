
/* 
TODO:
   - force login... or at least put a link
   - add 3 charts
   - correct ecapi API
   - make a colour templage
*/

function afelDisplayDailyData(data, date){
//    console.log(timeline);
    var today = new Date(); 
    jQuery("#afelcharts").append('<div id="afeltimeline" style="margin-bottom: 20px; height: 450px; width: 100%;"></div><div id="popularsites" style="width: 48%; height: 450px; float: left"></div><div id="popularpages" style="width: 48%; height: 450px; float: right"></div><div id="wordcloud" style="width: 100%; height: 450px"></div>');
    var timeline = generateTimeLineData(data, today);
    var chart = new CanvasJS.Chart("afeltimeline", {
	// theme: "theme2",//theme1
	title:{
	    text: "Timeline"              
	},
	animationEnabled: true,	
	data: [              
	    {
		// Change type to "bar", "area", "spline", "pie",etc.
		type: "spline",
		dataPoints: timeline
	    }
	]
    });
    chart.render();
    var pagecount = generatePageCount(data, today);
    var poppages = [];
    for (var i = 0; i < 12 && i < pagecount.length; i++){
	poppages.push({"label": pagecount[i][0], "y": pagecount[i][1]});
    }
    var chart2 = new CanvasJS.Chart("popularpages", {
	// theme: "theme2",//theme1
	title:{
	    text: "Frequent Pages",
	    margin: 10
	},
	axisX: {
	    labelWrap: false,
	    labelFontSize: 10, 
	    reversed: true,
	    interval: 1
	},
	animationEnabled: true,	
	data: [              
	    {
		// Change type to "bar", "area", "spline", "pie",etc.
		type: "bar",
		dataPoints: poppages
	    }
	]
    });
    chart2.render();  
    var sitecount = generateSiteCount(pagecount);
    var popsites = [];
    for (var i = 0; i < 12 && i < sitecount.length; i++){
	popsites.push({"label": sitecount[i][0], "y": sitecount[i][1]});
    }
    var chart3 = new CanvasJS.Chart("popularsites", {
	// theme: "theme2",//theme1
	title:{
	    text: "Frequent Sites",
	    margin: 10
	},
	axisX: {
	    labelWrap: false,
	    labelFontSize: 10, 
	    reversed: true,
	    interval: 1
	},
	animationEnabled: true,	
	data: [              
	    {
		// Change type to "bar", "area", "spline", "pie",etc.
		type: "bar",
		dataPoints: popsites
	    }
	]
    });
    chart3.render();  
}
//   jQuery("#afelcharts").append("hello guys");

function generateSiteCount(pagecount){
    var sitecount = {};
    for (var i in pagecount){
	var domain = url_domain(pagecount[i][0]);
	if (sitecount[domain]) sitecount[domain]++;
	else sitecount[domain]=1;
    }
    // sort pagecount
    var tmpa = [];
    for(var key in sitecount) tmpa.push([key, sitecount[key]]);
    tmpa.sort(function(a, b) {
	return b[1] - a[1];
    });
    return tmpa;  
}

function generatePageCount(data, today){
    var pagecount={};
    for (var dp in data["global:activities"]){
	var act = data["global:activities"][dp];    
	for (var time in act["global:atTime"]){
	    var date = new Date(Math.round(parseFloat(act["global:atTime"])));
	    if (date.getYear() == today.getYear() && date.getMonth() == today.getMonth() && date.getDay() == today.getDay()){
		if (pagecount[act["global:resource"][0]["global:url"][0]]) pagecount[act["global:resource"][0]["global:url"][0]]++;
		else pagecount[act["global:resource"][0]["global:url"][0]] = 1;
	    }
	}
    }
    // sort pagecount
    var tmpa = [];
    for(var key in pagecount) tmpa.push([key, pagecount[key]]);
    tmpa.sort(function(a, b) {
	return b[1] - a[1];
    });
    return tmpa;  
}

function generateTimeLineData(data, today){
    var cdata = {};
    for (var dp in data["global:activities"]){
	var act = data["global:activities"][dp];
	for (var time in act["global:atTime"]){
	    // 10 mins
	    var date = new Date(Math.round(parseFloat(act["global:atTime"])));
//	    console.log(date);
	    var hours = date.getHours();
	    if (hours < 10) hours = "0"+hours;
	    var minutes = date.getMinutes();
	    var index = hours+":"+(Math.floor(minutes/10))+"0";
	    if (date.getYear() == today.getYear() && date.getMonth() == today.getMonth() && date.getDay() == today.getDay()){
		if (!cdata[index]) cdata[index]=1;
		else cdata[index]++;
	    }
	}
    }
   cdata  = sortObject(cdata);
   var result = [];
    for(var index in cdata){
	result.push({"x": new Date(today.getYear(), today.getMonth(), today.getDay(), index.substring(0, index.indexOf(':')), index.substring(index.indexOf(':')+1)), "y": cdata[index]});
    }
//<    console.log(result);
    return result;
}

function sortObject(o) {
    return Object.keys(o).sort().reduce((r, k) => (r[k] = o[k], r), {});
}

function url_domain(data) {
  var    a      = document.createElement('a');
         a.href = data;
  return a.hostname;
}
