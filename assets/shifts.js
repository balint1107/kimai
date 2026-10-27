import './shifts.scss';
import {calendarDay} from './shifts-calendar';
import {compactCalendar} from './shifts-mobile-calendar';
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
    let places=JSON.parse(root.dataset.places), records=[], absences=[], calendar=null, calendarInteraction=null;
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
        if (form.elements.event_type.value!=='shift') {return;}
        const begin=DateTime.fromISO(form.elements.begin.value,{zone:'Europe/Budapest'}), end=DateTime.fromISO(form.elements.end.value,{zone:'Europe/Budapest'});
        const seconds=end.diff(begin,'seconds').seconds;
        document.getElementById('shift-preview').textContent=Number.isFinite(seconds)&&seconds>0 ? `${hours(seconds)} · ${money.format(seconds/3600*Number(form.elements.rate.value))}` : text.invalid_time;
        const overlap=records.some(r=>String(r.id)!==form.elements.id.value&&!r.cancelled&&DateTime.fromISO(r.start)<end&&DateTime.fromISO(r.end)>begin);
        document.getElementById('shift-overlap').classList.toggle('d-none',!overlap);
    }
    /** Switches new-event layouts, clearing only the auto-filled holiday name and preserving user-entered names. */
    function changeEventType() {
        const holiday=form.elements.event_type.value==='time_off',title=form.elements.title;
        if (!holiday&&title.dataset.defaultHolidayName) {title.value='';delete title.dataset.defaultHolidayName;}
        if (holiday&&!title.value) {title.value=text.time_off;title.dataset.defaultHolidayName='1';}
        form.elements.all_day.checked=holiday;form.elements.color.value=holiday?'#7952b3':'#0f766e';
        eventLayout();
    }
    /** Adapts shared dates and enabled/required fields to the selected event type without submitting hidden accounting fields. */
    function eventLayout() {
        const kind=form.elements.event_type.value,isShift=kind==='shift',allDay=!isShift&&form.elements.all_day.checked;
        const fields=document.getElementById('event-shift-fields');fields.disabled=!isShift;fields.classList.toggle('d-none',!isShift);
        document.getElementById('event-title-field').classList.toggle('d-none',isShift);
        form.elements.title.disabled=isShift;form.elements.title.required=!isShift;
        document.getElementById('event-color-field').classList.toggle('d-none',isShift);form.elements.color.disabled=isShift;
        document.getElementById('event-all-day-field').classList.toggle('d-none',isShift);form.elements.all_day.disabled=isShift;
        document.getElementById('event-calendar-help').classList.toggle('d-none',isShift);
        document.getElementById('shift-preview').classList.toggle('d-none',!isShift);
        document.getElementById('shift-overlap').classList.add('d-none');form.elements.note.maxLength=isShift?2000:200;
        for (const key of ['begin','end']) {
            const field=form.elements[key],value=field.value;
            field.type=allDay?'date':'datetime-local';field.step=allDay?'1':'60';
            field.value=allDay?value.slice(0,10):value.length===10?`${value}T${key==='begin'?'08:00':'09:00'}`:value;
        }
        preview();
    }
    /** Opens a shift or calendar-only record in one editor; new entries can select type, saved IDs/review entries keep their type. */
    function edit(record={}) {
        form.reset();delete form.elements.title.dataset.defaultHolidayName;populatePlaces();
        const kind=record.kind || 'shift',isShift=kind==='shift';
        form.elements.event_type.value=kind;form.elements.event_type.disabled=!!(record.id||record.review_item);
        for (const key of ['begin','end']) {form.elements[key].type='datetime-local';form.elements[key].step='60';}
        for (const key of ['id','review_item','workplace','rate','category','note']) {if (record[key]!==undefined&&record[key]!==null) {form.elements[key].value=record[key];}}
        form.elements.id.value=isShift?(record.id || ''):(record.absence_id || '');
        form.elements.title.value=isShift?'':(record.event_title || (kind==='time_off'?text.time_off:record.title || ''));
        form.elements.color.value=record.color || (kind==='time_off'?'#7952b3':'#0f766e');
        if (!isShift) {form.elements.note.value=record.note ?? (kind==='time_off'?record.title || '':'');}
        form.elements.all_day.checked=!isShift&&!!record.allDay;
        form.elements.begin.value=wall(record.start || record.begin || (record.date ? `${record.date}T08:00` : '')) || DateTime.now().setZone('Europe/Budapest').startOf('hour').toFormat("yyyy-MM-dd'T'HH:mm");
        form.elements.end.value=wall(record.end) || DateTime.fromISO(form.elements.begin.value,{zone:'Europe/Budapest'}).plus({hours:1}).toFormat("yyyy-MM-dd'T'HH:mm");
        if (record.allDay) {form.elements.end.value=DateTime.fromISO(form.elements.end.value,{zone:'Europe/Budapest'}).minus({days:1}).toFormat("yyyy-MM-dd'T'HH:mm");}
        if (record.review_item && !record.id && !record.begin) {form.elements.begin.value='';form.elements.end.value='';}
        form.elements.cancelled.checked=!!record.cancelled;
        if (isShift&&!record.id&&record.rate===undefined&&places.length) {form.elements.workplace.value=places[0].id;defaults();}
        document.getElementById('shift-delete').classList.toggle('d-none',!record.id);
        document.getElementById('shift-editor-error').classList.add('d-none');
        document.getElementById('shift-editor-title').textContent=record.review_item?`${text.review} · ${record.date || ''}`:record.id?(isShift?text.shift_type:kind==='event'?text.event:text.time_off):text.new_event;
        eventLayout();dialog.showModal();
    }
    /** Classifies FullCalendar Date in Budapest for day backgrounds, headers and accessible tooltips. */
    function dayMarker(date) {return calendarDay(DateTime.fromJSDate(date,{zone:'Europe/Budapest'}).toISODate());}
    populatePlaces();
    document.getElementById('shift-close').addEventListener('click',()=>dialog.close());
    document.getElementById('shift-new')?.addEventListener('click',()=>edit());
    /** Runs an immediate two-way sync and refreshes visible records without resetting the selected calendar view. */
    async function synchronizeCalendar() {
        const button=document.getElementById('shift-sync');
        button.disabled=true;button.setAttribute('aria-busy','true');
        document.getElementById('shift-error').classList.add('d-none');
        try {
            const body=new FormData();body.set('_token',root.dataset.token);body.set('action','sync');
            const response=await fetch(root.dataset.syncUrl,{method:'POST',headers:{Accept:'application/json'},body});
            if (!response.ok || !(await response.json()).success) {throw new Error(text.google_sync_error);}
            await refresh();
        } catch(e) {error(e.message);} finally {button.disabled=false;button.removeAttribute('aria-busy');}
    }
    document.getElementById('shift-sync')?.addEventListener('click',synchronizeCalendar);
    form.elements.event_type.addEventListener('change',changeEventType);
    form.elements.title.addEventListener('input',()=>{delete form.elements.title.dataset.defaultHolidayName;});
    form.elements.all_day.addEventListener('change',eventLayout);
    form.addEventListener('input',preview); form.elements.workplace.addEventListener('change',defaults);
    form.addEventListener('submit',async event=>{
        event.preventDefault(); const button=event.submitter; button.disabled=true;
        try {
            const kind=form.elements.event_type.value,data=Object.fromEntries(new FormData(form));
            if (kind==='shift') {data.cancelled=form.elements.cancelled.checked;await send(root.dataset.saveUrl,data);}
            else {data.kind=kind;data.all_day=form.elements.all_day.checked;await send(root.dataset.timeOffSaveUrl,data);}
            dialog.close();await refresh();
        } catch(e){error(e.message,'shift-editor-error');} finally {button.disabled=false;}
    });
    document.getElementById('shift-delete').addEventListener('click',async()=>{
        if (!window.confirm(text.delete_event_confirm)) {return;}
        try {await send(form.elements.event_type.value==='shift'?root.dataset.deleteUrl:root.dataset.timeOffDeleteUrl,{id:form.elements.id.value}); dialog.close(); await refresh();} catch(e){error(e.message,'shift-editor-error');}
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
    const calendarElement=document.getElementById('shift-calendar');
    if (calendarElement) {
        calendar=new Calendar(calendarElement,{
            plugins:[dayGridPlugin,timeGridPlugin,interactionPlugin,zonePlugin],locale:huLocale,timeZone:'Europe/Budapest',firstDay:1,
            initialView:'timeGridWeek',height:Math.max(560,window.innerHeight-350),expandRows:true,scrollTime:'07:00:00',nowIndicator:true,editable:true,selectable:true,
            headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,timeGridDay'},slotDuration:'01:00:00',slotLabelInterval:'01:00:00',snapDuration:'00:15:00',
            eventTimeFormat:{hour:'2-digit',minute:'2-digit',hour12:false},slotLabelFormat:{hour:'2-digit',minute:'2-digit',hour12:false},
            dateClick:info=>{if(!calendarInteraction || calendarInteraction.canOpen()){edit({start:info.dateStr.length===10?`${info.dateStr}T08:00`:info.dateStr});}},
            select:info=>{if(info.view.type!=='dayGridMonth' && (!calendarInteraction || calendarInteraction.canOpen())){edit({start:info.startStr,end:info.endStr});}},
            eventClick:info=>{if(!calendarInteraction || calendarInteraction.canOpen()){if(info.event.extendedProps.kind){edit(absences.find(r=>r.id===info.event.id));}else{edit(records.find(r=>String(r.id)===info.event.id));}}},
            dayCellClassNames:info=>[`shift-day-${dayMarker(info.date).kind}`],
            dayHeaderClassNames:info=>[`shift-day-${dayMarker(info.date).kind}`],
            dayCellDidMount:info=>{const marker=dayMarker(info.date);if(marker.label){info.el.title=marker.label;}},
            dayHeaderDidMount:info=>{const marker=dayMarker(info.date);if(marker.label){info.el.title=marker.label;}},
            datesSet:info=>{document.getElementById('calendar-swap-notice').classList.toggle('d-none',dayMarker(info.start).known&&dayMarker(new Date(info.end.valueOf()-1)).known);requestAnimationFrame(()=>calendarInteraction?.update());},
            eventClassNames:info=>info.event.extendedProps.state==='cancelled'?['state-cancelled']:[],
            /** Returns wrapped text nodes for FullCalendar info; only cancellation needs a state label. */
            eventContent:info=>{const label=document.createElement('div');label.className='shift-event-label';label.textContent=info.event.extendedProps.kind==='event'?`${info.timeText} ${info.event.title}`:info.event.extendedProps.kind==='time_off'?`${info.timeText} ${info.event.extendedProps.event_title?info.event.title:text.time_off+(info.event.title?' · '+info.event.title:'')}`:`${info.timeText} ${info.event.title}${info.event.extendedProps.state==='cancelled'?' · '+text.cancelled:''}`;return {domNodes:[label]};},
            /** Stores info.el's stable tap identity and tooltip, keeping completion/planning state exclusive to reports. */
            eventDidMount:info=>{info.el.dataset.shiftEventId=info.event.id;info.el.title=info.event.extendedProps.kind?`${info.event.title || text.time_off}${info.event.extendedProps.note&&info.event.extendedProps.note!==info.event.title?' · '+info.event.extendedProps.note:''}`:`${info.event.title}${info.event.extendedProps.state==='cancelled'?' · '+text.cancelled:''} · ${money.format(info.event.extendedProps.amount)}`;},
            eventDrop:move,eventResize:move,
        });
        calendar.render();
        calendarInteraction=compactCalendar(calendar,calendarElement,{
            range:range=>edit({start:range.start,end:range.end}),
            tap:start=>edit({start}),
            event:id=>{const record=absences.find(r=>r.id===id) || records.find(r=>String(r.id)===id);if(record){edit(record);}},
        });
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

/** Initializes the record-by-record Google review editor, retaining explicit overrides when workplace defaults are offered. */
function initializeCalendarReview() {
    const form=document.querySelector('[data-calendar-review]');
    if (!form) {return;}
    /** Shows only the selected type's controls and converts inclusive date inputs when whole-day mode changes. */
    function layout() {
        const shift=form.elements.kind.value==='shift';
        if (shift) {form.elements.all_day.checked=false;}
        for (const el of form.querySelectorAll('[data-review-shift]')) {el.hidden=!shift;}
        for (const el of form.querySelectorAll('[data-review-general]')) {el.hidden=shift;}
        for (const name of ['workplace','category','rate']) {form.elements[name].required=shift;}
        form.elements.title.required=!shift;
        const type=form.elements.all_day.checked ? 'date' : 'datetime-local';
        for (const name of ['begin','end']) {
            const el=form.elements[name], value=el.value;
            if (el.type!==type) {el.type=type;el.value=type==='date' ? value.slice(0,10) : (value ? `${value.slice(0,10)}T00:00` : '');}
        }
        form.elements.note.maxLength=shift ? 2000 : 200;
    }
    form.elements.kind.addEventListener('change',layout);
    form.elements.all_day.addEventListener('change',layout);
    form.elements.workplace.addEventListener('change',()=>{
        const option=form.elements.workplace.selectedOptions[0];
        if (!form.elements.category.value) {form.elements.category.value=option.dataset.category || '';}
        if (!form.elements.rate.value) {form.elements.rate.value=option.dataset.rate || '';}
    });
    layout();
}
if (document.readyState==='loading') {document.addEventListener('DOMContentLoaded',initializeCalendarReview);} else {initializeCalendarReview();}
