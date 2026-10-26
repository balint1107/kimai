// Verified annual workday swaps; sources and supported years are listed in docs/shift-accounting.md.
const swaps={
    2023:{work:[],rest:[]},
    2024:{work:['08-03','12-07','12-14'],rest:['08-19','12-24','12-27']},
    2025:{work:['05-17','10-18','12-13'],rest:['05-02','10-24','12-24']},
    2026:{work:['01-10','08-08','12-12'],rest:['01-02','08-21','12-24']},
};
const years=new Map();

/** Returns Gregorian Easter Sunday for integer year as a UTC date, avoiding host-zone/DST arithmetic. */
function easter(year) {
    const a=year%19,b=Math.floor(year/100),c=year%100,d=Math.floor(b/4),e=b%4;
    const f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30;
    const i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451);
    const value=h+l-7*m+114;
    return new Date(Date.UTC(year,Math.floor(value/31)-1,value%31+1));
}

/** Classifies Budapest ISO date (YYYY-MM-DD); returns kind/label plus whether annual swaps were verified. */
export function calendarDay(iso) {
    const year=Number(iso.slice(0,4));
    if (!years.has(year)) {
        const days=new Map(Object.entries({
            '01-01':'Újév','03-15':'Nemzeti ünnep','05-01':'A munka ünnepe','08-20':'Az államalapítás ünnepe',
            '10-23':'Nemzeti ünnep','11-01':'Mindenszentek','12-25':'Karácsony','12-26':'Karácsony másnapja',
        }).map(([day,label])=>[`${year}-${day}`,{kind:'public_holiday',label}]));
        for (const [offset,label] of [[-2,'Nagypéntek'],[0,'Húsvétvasárnap'],[1,'Húsvéthétfő'],[49,'Pünkösdvasárnap'],[50,'Pünkösdhétfő']]) {
            if (offset===-2 && year<2017) {continue;}
            const date=easter(year);date.setUTCDate(date.getUTCDate()+offset);
            days.set(date.toISOString().slice(0,10),{kind:'public_holiday',label});
        }
        for (const day of swaps[year]?.work || []) {days.set(`${year}-${day}`,{kind:'workday',label:'Áthelyezett munkanap'});}
        for (const day of swaps[year]?.rest || []) {days.set(`${year}-${day}`,{kind:'rest_day',label:'Áthelyezett pihenőnap'});}
        years.set(year,days);
    }
    const day=years.get(year).get(iso),weekend=[0,6].includes(new Date(`${iso}T12:00:00Z`).getUTCDay());
    return {...(day || {kind:weekend?'weekend':'weekday',label:weekend?'Hétvége':''}),known:year in swaps};
}
