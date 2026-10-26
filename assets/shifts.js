import './shifts.scss';
import {calendarDay} from './shifts-calendar';
import {Calendar, createPlugin} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import huLocale from '@fullcalendar/core/locales/hu';
import {DateTime} from 'luxon';

/** Supplies FullCalendar named-zone calculations using the already installed Luxon library. */
class BudapestZone {
    /** Receives the requested IANA zone name from FullCalendar. */
    constructor(timeZoneName) { this.timeZoneName = timeZoneName; }
    /** Returns the UTC offset in minutes for FullCalendar's zero-based calendar array. */
    offsetForArray(a) { return DateTime.fromObject({year:a[0], month:a[1]+1, day:a[2], hour:a[3]||0, minute:a[4]||0, second:a[5]||0}, {zone:this.timeZoneName}).offset; }
    /** Converts epoch milliseconds into FullCalendar's calendar component array in the requested zone. */
    timestampToArray(ms) { const d=DateTime.fromMillis(ms,{zone:this.timeZoneName}); return [d.year,d.month-1,d.day,d.hour,d.minute,d.second,d.millisecond]; }
}
const zonePlugin = createPlugin({name:'shift-budapest-zone',namedTimeZonedImpl:BudapestZone});

/** Starts the authenticated calendar/report interface after the DOM is ready. */
function initialize() {
    const root=document.getElementById('shift-root');
    if (!root) {return;}
    const text=JSON.parse(root.dataset.texts), form=document.getElementById('shift-form'), dialog=document.getElementById('shift-dialog');
    let places=JSON.parse(root.dataset.places), records=[], absences=[], calendar=null;
    const money=new Intl.NumberFormat('hu-HU',{style:'currency',currency:'HUF',maximumFractionDigits:0});
    /** Formats seconds as a duration without wrapping after 24 hours. */
    const hours=(seconds)=>`${Math.floor(Math.round(seconds/60)/60)}:${String(Math.round(seconds/60)%60).padStart(2,'0')}`;
    /** Converts an ISO instant into a Budapest datetime-local control value. */
    const wall=(value)=>value ? DateTime.fromISO(value,{zone:'Europe/Budapest'}).toFormat("yyyy-MM-dd'T'HH:mm") : '';
    /** Shows a safely escaped error in the selected alert element. */
    function error(message, id='shift-error') { const el=document.getElementById(id); el.textContent=message; el.classList.remove('d-none'); }
    /** Sends JSON using the session CSRF token and throws localized server failures. */
    async function send(url,data) {
        const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.token},body:JSON.stringify(data)});
        const body=response.headers.get('content-type')?.includes('application/json') ? await response.json() : {error:`HTTP ${response.status}`};
        if (!response.ok) {throw new Error(body.error || `HTTP ${response.status}`);}
        return body;
    }
    /** Collects the active inclusive-date/report filters for data and exports. */
    function filters() {
        const params=new URLSearchParams();
        for (const key of ['from','to','workplace','category','state']) {
            const value=document.getElementById(`filter-${key}`)?.value;
            if (value) {params.set(key,value);}
        }
        return params;
    }
    /** Defaults report dates to the current Budapest year; calendar dates remain unrestricted. */
    function defaultReportDates() {
        if (!['summary','workplaces'].includes(root.dataset.tab)) {return;}
        const year=DateTime.now().setZone('Europe/Budapest').year;
        document.getElementById('filter-from').value=`${year}-01-01`;
        document.getElementById('filter-to').value=`${year}-12-31`;
    }
    /** Fills workplace selectors, preserving IDs; workplace reports require one explicit selection. */
    function populatePlaces() {
        for (const select of [form.elements.workplace,document.getElementById('filter-workplace')]) {
            if (!select) {continue;}
            const old=select.value; select.replaceChildren();
            select.add(new Option(select.id==='filter-workplace' ? (root.dataset.tab==='workplaces' ? text.select_workplace : text.all) : '—',''));
            for (const place of places) {select.add(new Option(place.name,place.id));}
            select.value=old;
        }
        const editButton=document.getElementById('workplace-edit');
        if (editButton) {editButton.disabled=!document.getElementById('filter-workplace').value;}
    }
    /** Opens a clean creation dialog, or edits defaults from optional place; saved shift rates stay untouched. */
    function editWorkplace(place=null) {
        const target=document.getElementById('workplace-form');
        target.reset();
        target.elements.id.value='';
        if (place) {for (const key of ['id','name','rate','category','color']) {target.elements[key].value=place[key];}}
        document.getElementById('workplace-title').textContent=place?text.edit_workplace:text.new_workplace;
        document.getElementById('workplace-error').classList.add('d-none');
        document.getElementById('workplace-dialog').showModal();
        target.elements.name.focus();
    }
    /** Renders one row per period with EFO/Számla column groups and completed / total values, including planned work. */
    function summaryReport(target,rows) {
        const container=document.getElementById(target); if (!container) {return;}
        const table=document.createElement('table'); table.className='table table-vcenter shift-summary-table';
        const head=table.createTHead(), major=head.insertRow(), minor=head.insertRow();
        const period=document.createElement('th'); period.rowSpan=2; period.scope='col'; period.textContent=text.period; major.append(period);
        for (const label of ['EFO','Számla']) {
            const group=document.createElement('th'); group.colSpan=3; group.scope='colgroup'; group.textContent=label; group.className='text-center shift-group-start'; major.append(group);
            for (const key of ['count','hours','amount']) {const cell=document.createElement('th');cell.scope='col';cell.textContent=text[key];if(key==='count'){cell.className='shift-group-start';}minor.append(cell);}
        }
        const total=document.createElement('th');total.rowSpan=2;total.scope='col';total.textContent=text.total_amount;total.className='shift-group-start';major.append(total);
        const body=table.createTBody();
        for (const row of rows) {
            const tr=body.insertRow();tr.insertCell().textContent=row.period;
            if (row.period===DateTime.now().setZone('Europe/Budapest').toFormat('yyyy-MM')) {tr.classList.add('shift-current-month');tr.setAttribute('aria-current','date');}
            for (const category of ['efo','invoice']) {
                for (const key of ['count','seconds','amount']) {
                    const cell=tr.insertCell(), progress=row[category];
                    const format=key==='seconds'?hours:key==='amount'?value=>money.format(value):String;
                    cell.textContent=`${format(progress.completed[key])} / ${format(progress.total[key])}`;
                    if(key==='count'){cell.className='shift-group-start';}
                }
            }
            const cell=tr.insertCell();cell.textContent=`${money.format(row.all.completed.amount)} / ${money.format(row.all.total.amount)}`;cell.className='shift-group-start fw-bold';
        }
        container.replaceChildren(table);
    }
    /** Reloads filtered reports/cards; workplace reports and exports remain empty until one workplace is selected. */
    async function refresh() {
        if (!document.getElementById('shift-totals')) {return;}
        const requiresPlace=root.dataset.tab==='workplaces', selected=document.getElementById('filter-workplace')?.value;
        if (requiresPlace) {
            document.getElementById('workplace-prompt').classList.toggle('d-none',!!selected);
            document.getElementById('workplace-reports').classList.toggle('d-none',!selected);
            document.getElementById('workplace-edit').disabled=!selected;
            for (const format of ['xlsx','csv']) {
                const link=document.getElementById(`export-${format}`);
                link.classList.toggle('disabled',!selected);link.setAttribute('aria-disabled',String(!selected));
                if (!selected) {link.removeAttribute('href');}
            }
            if (!selected) {
                document.getElementById('shift-totals').replaceChildren();
                for (const id of ['report-workplaces','report-workplace_years']) {document.getElementById(id).replaceChildren();}
                return;
            }
        }
        try {
            const params=filters(), response=await fetch(`${root.dataset.dataUrl}?${params}`);
            if (!response.ok) {throw new Error(`HTTP ${response.status}`);}
            const data=await response.json(); if (data.error) {throw new Error(data.error);}
            records=data.events; absences=data.holidays || []; places=data.places; populatePlaces();
            const currentMonth=DateTime.now().setZone('Europe/Budapest');
            const monthKey=currentMonth.toFormat('yyyy-MM');
            const monthLabel=currentMonth.setLocale('hu').toFormat('yyyy. LLLL');
            const totals=document.getElementById('shift-totals'); totals.replaceChildren();
            for (const state of ['completed','planned']) {
                const rows=data.groups.months.filter(row=>row.state===state && row.period===monthKey);
                const sum=rows.reduce((a,row)=>({count:a.count+row.count,seconds:a.seconds+row.seconds,amount:a.amount+row.amount}),{count:0,seconds:0,amount:0});
                const card=document.createElement('div'); card.className='col-md-6';
                const box=document.createElement('div'); box.className='card card-body';
                const heading=document.createElement('div'); heading.className='text-muted'; heading.textContent=`${text[state]} · ${monthLabel}`;
                const amount=document.createElement('div'); amount.className='h1 mb-1'; amount.textContent=money.format(sum.amount);
                const detail=document.createElement('div'); detail.textContent=`${sum.count} ${text.count} · ${hours(sum.seconds)} ${text.hours}`;
                box.append(heading,amount,detail); card.append(box); totals.append(card);
            }
            summaryReport('report-months',data.summary.months); summaryReport('report-years',data.summary.years); summaryReport('report-workplaces',data.summary.months); summaryReport('report-workplace_years',data.summary.years);
            if (calendar) { calendar.getEventSources().forEach(source=>source.remove()); calendar.addEventSource([...records,...absences]); }
            for (const format of ['xlsx','csv']) {document.getElementById(`export-${format}`).href=`${root.dataset[`${format}Url`]}?${params}`;}
            document.getElementById('shift-error').classList.add('d-none');
        } catch (e) { error(e.message); }
    }
    /** Applies the selected workplace's defaults to a new/changed workplace selection. */
    function defaults() {
        const place=places.find(p=>String(p.id)===form.elements.workplace.value);
        if (place) {form.elements.rate.value=place.rate; form.elements.category.value=place.category;}
        preview();
    }
    /** Recomputes the editor preview in Budapest, including DST elapsed time and overlap warnings. */
    function preview() {
        const begin=DateTime.fromISO(form.elements.begin.value,{zone:'Europe/Budapest'}), end=DateTime.fromISO(form.elements.end.value,{zone:'Europe/Budapest'});
        const seconds=end.diff(begin,'seconds').seconds;
        document.getElementById('shift-preview').textContent=Number.isFinite(seconds)&&seconds>0 ? `${hours(seconds)} · ${money.format(seconds/3600*Number(form.elements.rate.value))}` : text.invalid_time;
        const overlap=records.some(r=>String(r.id)!==form.elements.id.value&&!r.cancelled&&DateTime.fromISO(r.start)<end&&DateTime.fromISO(r.end)>begin);
        document.getElementById('shift-overlap').classList.toggle('d-none',!overlap);
    }
    /** Opens an existing record or a new interval, with optional import-review provenance. */
    function edit(record={}) {
        form.reset(); populatePlaces();
        for (const key of ['id','review_item','workplace','rate','category','note']) {if (record[key]!==undefined&&record[key]!==null) {form.elements[key].value=record[key];}}
        form.elements.begin.value=wall(record.start || record.begin || (record.date ? `${record.date}T08:00` : '')) || DateTime.now().setZone('Europe/Budapest').startOf('hour').toFormat("yyyy-MM-dd'T'HH:mm");
        form.elements.end.value=wall(record.end) || DateTime.fromISO(form.elements.begin.value,{zone:'Europe/Budapest'}).plus({hours:1}).toFormat("yyyy-MM-dd'T'HH:mm");
        if (record.review_item && !record.id && !record.begin) { form.elements.begin.value=''; form.elements.end.value=''; }
        form.elements.cancelled.checked=!!record.cancelled;
        if (!record.id && record.rate===undefined && places.length) {form.elements.workplace.value=places[0].id; defaults();}
        document.getElementById('shift-delete').classList.toggle('d-none',!record.id);
        document.getElementById('shift-editor-error').classList.add('d-none');
        document.getElementById('shift-editor-title').textContent=record.review_item?`${text.review} · ${record.date || ''}`:text.new_shift;
        preview(); dialog.showModal();
    }
    /** Opens optional absence event for editing; all-day exclusive ends become inclusive dates in the form. */
    function editTimeOff(event=null) {
        const target=document.getElementById('time-off-form');target.reset();
        target.elements.id.value=event?.absence_id || '';
        target.elements.all_day.checked=event?.allDay ?? true;
        const date=calendar?.getDate() || new Date();
        const begin=event?DateTime.fromISO(event.start,{zone:'Europe/Budapest'}):DateTime.fromJSDate(date,{zone:'Europe/Budapest'}).startOf('day');
        const end=event?DateTime.fromISO(event.end,{zone:'Europe/Budapest'}):begin.plus({hours:1});
        target.elements.begin.dataset.value=begin.toFormat("yyyy-MM-dd'T'HH:mm");
        target.elements.end.dataset.value=(event?.allDay?end.minus({days:1}):end).toFormat("yyyy-MM-dd'T'HH:mm");
        timeOffFields();target.elements.note.value=event?.title || '';
        document.getElementById('time-off-delete').classList.toggle('d-none',!event);
        document.getElementById('time-off-error').classList.add('d-none');
        document.getElementById('time-off-dialog').showModal();
    }
    /** Switches absence date controls without discarding chosen dates; partial-day defaults span one hour. */
    function timeOffFields() {
        const target=document.getElementById('time-off-form'),allDay=target.elements.all_day.checked;
        for (const key of ['begin','end']) {
            const field=target.elements[key],value=field.dataset.value || field.value;
            field.type=allDay?'date':'datetime-local';
            field.value=allDay?value.slice(0,10):value.length===10?`${value}T${key==='begin'?'08:00':'09:00'}`:value;
        }
    }
    /** Classifies FullCalendar Date in Budapest for day backgrounds, headers and accessible tooltips. */
    function dayMarker(date) {return calendarDay(DateTime.fromJSDate(date,{zone:'Europe/Budapest'}).toISODate());}
    populatePlaces();
    document.getElementById('shift-close').addEventListener('click',()=>dialog.close());
    document.getElementById('shift-new')?.addEventListener('click',()=>edit());
    form.addEventListener('input',preview); form.elements.workplace.addEventListener('change',defaults);
    form.addEventListener('submit',async event=>{
        event.preventDefault(); const button=event.submitter; button.disabled=true;
        try {const data=Object.fromEntries(new FormData(form)); data.cancelled=form.elements.cancelled.checked; await send(root.dataset.saveUrl,data); dialog.close(); await refresh();} catch(e){error(e.message,'shift-editor-error');} finally {button.disabled=false;}
    });
    document.getElementById('shift-delete').addEventListener('click',async()=>{
        if (!window.confirm(text.delete_confirm)) {return;}
        try {await send(root.dataset.deleteUrl,{id:form.elements.id.value}); dialog.close(); await refresh();} catch(e){error(e.message,'shift-editor-error');}
    });
    document.getElementById('workplace-form')?.addEventListener('submit',async event=>{
        event.preventDefault(); const button=event.submitter; button.disabled=true;
        try {
            const data=Object.fromEntries(new FormData(event.target));
            places=await send(root.dataset.workplaceUrl,data);populatePlaces();
            const saved=places.find(place=>data.id?String(place.id)===data.id:place.name===data.name.trim());
            if (saved) {document.getElementById('filter-workplace').value=saved.id;}
            document.getElementById('workplace-dialog').close();await refresh();
        } catch(e){error(e.message,'workplace-error');} finally {button.disabled=false;}
    });
    document.getElementById('workplace-new')?.addEventListener('click',()=>editWorkplace());
    document.getElementById('workplace-edit')?.addEventListener('click',()=>editWorkplace(places.find(place=>String(place.id)===document.getElementById('filter-workplace').value)));
    document.getElementById('workplace-close')?.addEventListener('click',()=>document.getElementById('workplace-dialog').close());
    for (const key of ['from','to','workplace','category','state']) {document.getElementById(`filter-${key}`)?.addEventListener('change',refresh);}
    document.getElementById('filter-reset')?.addEventListener('click',()=>{for (const key of ['from','to','workplace','category','state']) {document.getElementById(`filter-${key}`).value='';} defaultReportDates(); refresh();});
    const timeOffForm=document.getElementById('time-off-form');
    document.getElementById('time-off-new')?.addEventListener('click',()=>editTimeOff());
    document.getElementById('time-off-close')?.addEventListener('click',()=>document.getElementById('time-off-dialog').close());
    timeOffForm?.elements.all_day.addEventListener('change',()=>{
        for (const key of ['begin','end']) {timeOffForm.elements[key].dataset.value=timeOffForm.elements[key].value;}
        timeOffFields();
    });
    timeOffForm?.addEventListener('submit',async event=>{
        event.preventDefault();event.submitter.disabled=true;
        try {
            const data=Object.fromEntries(new FormData(timeOffForm));data.all_day=timeOffForm.elements.all_day.checked;
            await send(root.dataset.timeOffSaveUrl,data);document.getElementById('time-off-dialog').close();await refresh();
        } catch(e){error(e.message,'time-off-error');} finally {event.submitter.disabled=false;}
    });
    document.getElementById('time-off-delete')?.addEventListener('click',async()=>{
        if (!window.confirm(text.delete_confirm)) {return;}
        try {await send(root.dataset.timeOffDeleteUrl,{id:timeOffForm.elements.id.value});document.getElementById('time-off-dialog').close();await refresh();} catch(e){error(e.message,'time-off-error');}
    });
    const calendarElement=document.getElementById('shift-calendar');
    if (calendarElement) {
        calendar=new Calendar(calendarElement,{
            plugins:[dayGridPlugin,timeGridPlugin,interactionPlugin,zonePlugin],locale:huLocale,timeZone:'Europe/Budapest',firstDay:1,
            initialView:window.innerWidth<576?'timeGridDay':'timeGridWeek',height:Math.max(560,window.innerHeight-350),scrollTime:'07:00:00',nowIndicator:true,editable:true,selectable:true,
            headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,timeGridDay'},slotDuration:'00:15:00',snapDuration:'00:15:00',
            eventTimeFormat:{hour:'2-digit',minute:'2-digit',hour12:false},slotLabelFormat:{hour:'2-digit',minute:'2-digit',hour12:false},
            dateClick:info=>edit({start:info.dateStr.length===10?`${info.dateStr}T08:00`:info.dateStr}),
            select:info=>{if(info.view.type!=='dayGridMonth'){edit({start:info.startStr,end:info.endStr});}},
            eventClick:info=>info.event.extendedProps.kind==='time_off'?editTimeOff(absences.find(r=>r.id===info.event.id)):edit(records.find(r=>String(r.id)===info.event.id)),
            dayCellClassNames:info=>[`shift-day-${dayMarker(info.date).kind}`],
            dayHeaderClassNames:info=>[`shift-day-${dayMarker(info.date).kind}`],
            dayCellDidMount:info=>{const marker=dayMarker(info.date);if(marker.label){info.el.title=marker.label;}},
            dayHeaderDidMount:info=>{const marker=dayMarker(info.date);if(marker.label){info.el.title=marker.label;}},
            datesSet:info=>{document.getElementById('calendar-swap-notice').classList.toggle('d-none',dayMarker(info.start).known&&dayMarker(new Date(info.end.valueOf()-1)).known);},
            eventClassNames:info=>[`state-${info.event.extendedProps.state}`],
            eventContent:info=>{const label=document.createElement('div');label.textContent=info.event.extendedProps.kind==='time_off'?`${info.timeText} ${text.time_off}${info.event.title?' · '+info.event.title:''}`:`${info.timeText} ${info.event.title} · ${text[info.event.extendedProps.state]}`;return {domNodes:[label]};},
            eventDidMount:info=>{info.el.title=info.event.extendedProps.kind==='time_off'?`${text.time_off} · ${info.event.title}`:`${info.event.title} · ${text[info.event.extendedProps.state]} · ${money.format(info.event.extendedProps.amount)}`;},
            eventDrop:move,eventResize:move,
        });
        calendar.render();
        document.getElementById('calendar-date').addEventListener('change',event=>{if(event.target.value){calendar.gotoDate(event.target.value);}});
    }
    /** Saves a drag/resize using ISO instants; reverts the UI when validation or the network fails. */
    async function move(info) {
        const record=records.find(r=>String(r.id)===info.event.id);
        try {await send(root.dataset.saveUrl,{...record,begin:info.event.startStr,end:info.event.endStr}); await refresh();} catch(e){info.revert();error(e.message);}
    }
    defaultReportDates(); refresh(); setInterval(refresh,60000);
    const review=JSON.parse(root.dataset.review || 'null');
    if (review) {edit(review);}
}
if (document.readyState==='loading') {document.addEventListener('DOMContentLoaded',initialize);} else {initialize();}
