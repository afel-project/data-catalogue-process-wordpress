/* 
TODO:
   - force login... or at least put a link
   - add 3 charts
   - correct ecapi API
   - make a colour template
*/
function afelDisplayDailyData(data, date, cap) {
    if (typeof cap == 'undefined') cap = 12; // Default to 12 rows
    var today = new Date();
    var colorMap = {}; // assigns pages to sites so we can adopt a color scheme
    const chartColors = [
    	"#000080", "#006400", "#32CD32",
        "#C71585", "#FF8C00", "#FFD700",
        "#5F9EA0", "#708090", "#800080",
        "#8B0000", "#9ACD32", "#A52A2A" 
    ];
    var usedColors = [];

    // CHART 0: visit timeline
    var timeline = generateTimeLineData(data, today);
    var chart = new CanvasJS.Chart("afeltimeline", {
        // theme: "theme2",//theme1
        title: {
            text: "Timeline"
        },
        animationEnabled: true,
        data: [{
            // Change type to "bar", "area", "spline", "pie",etc.
            type: "spline",
            dataPoints: timeline
        }]
    });
    chart.render();

    // CHART 1: visited pages
    // 0: the page URL, 1: #visits, 2: site
    var pagecount = generatePageCount(data, today);
    var sitecount = generateSiteCount(pagecount);


    var popsites = [];
    for (var i = 0; i < cap && i < sitecount.length; i++) {
        var col = chartColors[i % chartColors.length];
        colorMap[sitecount[i][0]] = col
        popsites.push({
            label: sitecount[i][0],
            y: sitecount[i][1],
            color: col
        });
        usedColors.push(col);
    }
    var poppages = [];
    for (var i = 0; i < cap && i < pagecount.length; i++) {
        var pg = pagecount[i][0];
        var pg_short = pg.replace(/^https?\:\/\//i, "");
        var entry = {
            label: pg_short,
            y: pagecount[i][1],
        };
        if (colorMap[pagecount[i][2]]) {
            entry.color = colorMap[pagecount[i][2]]
        };
        poppages.push(entry);
    }


    var chart2 = new CanvasJS.Chart("popularpages", {
        // theme: "theme2",//theme1
        title: {
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
        data: [{
            // Change type to "bar", "area", "spline", "pie",etc.
            type: "bar",
            dataPoints: poppages
        }]
    });

    var chart3 = new CanvasJS.Chart("popularsites", {
        // theme: "theme2",//theme1
        title: {
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
        data: [{
            // Change type to "bar", "area", "spline", "pie",etc.
            type: "bar",
            dataPoints: popsites
        }]
    });

    chart2.render();
    chart3.render();
}

function generateSiteCount(pagecount) {
    var sitecount = {};
    for (var i in pagecount) {
        var domain = pagecount[i][2];
        if (sitecount[domain]) sitecount[domain]++;
        else sitecount[domain] = 1;
    }
    // sort pagecount
    var tmpa = [];
    for (var key in sitecount) tmpa.push([key, sitecount[key]]);
    tmpa.sort(function(a, b) {
        return b[1] - a[1];
    });
    return tmpa;
}

function generatePageCount(data, today) {
    var pagecount = {};
    for (var dp in data["global:activities"]) {
        var act = data["global:activities"][dp];
        for (var time in act["global:atTime"]) {
            var date = new Date(Math.round(parseFloat(act["global:atTime"])));
            if (date.getYear() == today.getYear() && date.getMonth() == today.getMonth() && date.getDay() == today.getDay()) {
                if (pagecount[act["global:resource"][0]["global:url"][0]]) pagecount[act["global:resource"][0]["global:url"][0]]++;
                else pagecount[act["global:resource"][0]["global:url"][0]] = 1;
            }
        }
    }
    var tmpa = [];
    for (var key in pagecount) tmpa.push([key, pagecount[key], url_domain(key)]);
    // sort pagecount, top visited first
    tmpa.sort(function(a, b) {
        return b[1] - a[1];
    });
    return tmpa;
}

function generateTimeLineData(data, today) {
    var cdata = {};
    for (var dp in data["global:activities"]) {
        var act = data["global:activities"][dp];
        for (var time in act["global:atTime"]) {
            // 10 mins
            var date = new Date(Math.round(parseFloat(act["global:atTime"])));
            var hours = date.getHours();
            if (hours < 10) hours = "0" + hours;
            var minutes = date.getMinutes();
            var index = hours + ":" + (Math.floor(minutes / 10)) + "0";
            if (date.getYear() == today.getYear() && date.getMonth() == today.getMonth() && date.getDay() == today.getDay()) {
                if (!cdata[index]) cdata[index] = 1;
                else cdata[index]++;
            }
        }
    }
    cdata = sortObject(cdata);
    var result = [];
    for (var index in cdata) {
        result.push({
            "x": new Date(today.getYear(), today.getMonth(), today.getDay(), index.substring(0, index.indexOf(':')), index.substring(index.indexOf(':') + 1)),
            "y": cdata[index]
        });
    }
    return result;
}

function sortObject(o) {
    return Object.keys(o).sort().reduce((r, k) => (r[k] = o[k], r), {});
}

function url_domain(data) {
    var a = document.createElement('a');
    a.href = data;
    return a.hostname;
}