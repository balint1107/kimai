import {DateTime} from 'luxon';

/** Gives calendar/element a three-column viewport; handlers.range receives released ISO endpoints, handlers.tap an ISO start and handlers.event a saved event ID. Returns sizing/editor guards. */
export function compactCalendar(calendar, element, handlers) {
    let compact=false, layoutKey='', gesture=null, ignoreUntil=0;
    /** Returns whether a click/release may open an editor; movement suppresses the synthetic click following a drag. */
    function canOpen() { return !gesture?.selecting && performance.now()>ignoreUntil; }
    /** Sizes the whole seven-day canvas inside a horizontally scrollable viewport, leaving toolbar and day view at normal width. */
    function update() {
        const viewport=element.querySelector('.fc-view-harness');
        if (!viewport) {return;}
        const type=calendar.view.type, narrow=element.clientWidth<768 && ['timeGridWeek','dayGridMonth'].includes(type);
        const axis=type==='timeGridWeek' ? element.querySelector('.fc-timegrid-axis')?.getBoundingClientRect().width || 0 : 0;
        const width=viewport.clientWidth, key=`${narrow}:${type}:${width}:${axis}`;
        if (layoutKey===key) {return;}
        layoutKey=key;
        element.style.setProperty('--shift-calendar-width',`${axis+(width-axis)*7/3}px`);
        element.style.setProperty('--shift-calendar-pan',`${viewport.scrollLeft}px`);
        element.classList.toggle('shift-three-days',narrow);
        viewport.tabIndex=narrow ? 0 : -1;
        if (compact!==narrow) {
            compact=narrow;
            calendar.batchRendering(()=>{calendar.setOption('selectable',!compact);calendar.setOption('editable',!compact);});
        }
        calendar.updateSize();
    }
    /** Returns the fractional wall-clock minute at client y, clamped to this week's displayed day; used only for a timed week grid. */
    function minuteAt(y) {
        const row=element.querySelector('.fc-timegrid-slot-lane').getBoundingClientRect();
        return Math.max(0,Math.min(1439.999,(y-row.top)/row.height*60));
    }
    /** Highlights the held day's interval through client y without opening an editor; endpoints include the current 15-minute slot. */
    function selectRange(y) {
        const anchor=Math.floor(gesture.anchor.minute/15)*15;
        const current=Math.max(0,Math.min(1425,Math.floor((gesture.anchor.minute+(y-gesture.y)*60/gesture.anchor.rowHeight)/15)*15));
        const start=Math.min(anchor,current),end=Math.max(anchor,current)+15;
        const base=DateTime.fromISO(gesture.anchor.date,{zone:'Europe/Budapest'});
        const iso=minute=>base.plus({days:Math.floor(minute/1440)}).set({hour:Math.floor(minute%1440/60),minute:minute%60}).toISO();
        gesture.range={start:iso(start),end:iso(end)};
        calendar.select(gesture.range);
    }
    /** Tracks a primary pointer; a stationary 500ms week hold arms selection, while earlier movement chooses two-axis scrolling. */
    function start(event) {
        if (!compact || !event.isPrimary || event.button!==0 || !event.target.closest('.fc-view-harness')) {return;}
        const viewport=element.querySelector('.fc-view-harness');
        const scroller=element.querySelector('.fc-scroller-liquid-absolute') || [...element.querySelectorAll('.fc-scroller')].find(el=>el.scrollHeight>el.clientHeight);
        const column=calendar.view.type==='timeGridWeek' && [...element.querySelectorAll('.fc-timegrid-col[data-date]')].find(el=>{const r=el.getBoundingClientRect();return event.clientX>=r.left&&event.clientX<r.right;});
        const grid=scroller?.getBoundingClientRect();
        const timed=column && grid && event.clientY>=grid.top && event.clientY<grid.bottom && event.clientX>=viewport.getBoundingClientRect().left+(element.querySelector('.fc-timegrid-axis')?.getBoundingClientRect().width || 0);
        gesture={id:event.pointerId,x:event.clientX,y:event.clientY,left:viewport.scrollLeft,top:scroller?.scrollTop || 0,pageTop:window.scrollY,viewport,scroller,dragging:false,selecting:false,eventId:event.target.closest('.fc-event')?.dataset.shiftEventId,day:event.target.closest('.fc-daygrid-day[data-date]')?.dataset.date,anchor:timed?{date:column.dataset.date,minute:minuteAt(event.clientY),rowHeight:element.querySelector('.fc-timegrid-slot-lane').getBoundingClientRect().height}:null};
        ignoreUntil=0;
        if (gesture.anchor) {
            gesture.timer=setTimeout(()=>{
                if (!gesture || gesture.id!==event.pointerId || gesture.dragging) {return;}
                gesture.selecting=true;
                viewport.classList.add('shift-selecting');
                viewport.setPointerCapture(event.pointerId);
                selectRange(event.clientY);
            },500);
        }
    }
    /** Scrolls both axes after a short hold, or updates the highlighted interval after a long hold; moving beyond eight pixels suppresses taps. */
    function move(event) {
        if (!gesture || event.pointerId!==gesture.id) {return;}
        const dx=event.clientX-gesture.x,dy=event.clientY-gesture.y;
        if (Math.hypot(dx,dy)>8) {ignoreUntil=performance.now()+500;}
        if (gesture.selecting) {
            event.preventDefault();
            selectRange(event.clientY);
            return;
        }
        if (!gesture.dragging && Math.hypot(dx,dy)>8) {
            clearTimeout(gesture.timer);
            gesture.dragging=true;
            gesture.viewport.setPointerCapture(event.pointerId);
        }
        if (gesture.dragging) {
            event.preventDefault();
            gesture.viewport.scrollLeft=gesture.left-dx;
            if (gesture.scroller) {gesture.scroller.scrollTop=gesture.top-dy;}
            const consumed=gesture.scroller ? gesture.scroller.scrollTop-gesture.top : 0;
            window.scrollTo(window.scrollX,gesture.pageTop-dy-consumed);
        }
    }
    /** On release opens one range/event/cell editor; handling compact taps directly avoids FullCalendar's post-scroll click suppression. Cancellation never opens an editor. */
    function finish(event) {
        if (!gesture || gesture.id!==event.pointerId) {return;}
        clearTimeout(gesture.timer);
        const range=gesture.selecting && event.type==='pointerup' ? gesture.range : null;
        const tap=!gesture.dragging && !gesture.selecting && event.type==='pointerup' && Math.hypot(event.clientX-gesture.x,event.clientY-gesture.y)<=8;
        const eventId=tap ? gesture.eventId : undefined;
        const start=tap && eventId===undefined ? (gesture.anchor ? DateTime.fromISO(gesture.anchor.date,{zone:'Europe/Budapest'}).set({hour:Math.floor(gesture.anchor.minute/60),minute:Math.floor(gesture.anchor.minute%60/15)*15}).toISO() : gesture.day ? `${gesture.day}T08:00` : null) : null;
        if (gesture.selecting) {calendar.unselect();}
        gesture.viewport.classList.remove('shift-selecting');
        if (gesture.dragging || gesture.selecting || eventId!==undefined || start || event.type==='pointercancel' || Math.hypot(event.clientX-gesture.x,event.clientY-gesture.y)>8) {ignoreUntil=performance.now()+500;}
        gesture=null;
        if (range) {handlers.range(range);}else if (eventId!==undefined) {handlers.event(eventId);}else if (start) {handlers.tap(start);}
    }
    element.addEventListener('pointerdown',start,true);
    document.addEventListener('pointermove',move,{capture:true,passive:false});
    document.addEventListener('pointerup',finish,true);
    document.addEventListener('pointercancel',finish,true);
    /** Reserves long holds inside the compact grid for interval selection rather than the browser's context menu. */
    element.addEventListener('contextmenu',event=>{if(compact && event.target.closest('.fc-view-harness')){event.preventDefault();}});
    /** Keeps the week time axis over its original viewport position while every day/header scrolls together. */
    element.addEventListener('scroll',event=>{if (event.target.matches('.fc-view-harness')) {element.style.setProperty('--shift-calendar-pan',`${event.target.scrollLeft}px`);}},true);
    /** Blocks the trailing synthetic grid click after a swipe, including swipes starting on an existing event. */
    element.addEventListener('click',event=>{if (event.target.closest('.fc-view-harness') && !canOpen()) {event.preventDefault();event.stopImmediatePropagation();}},true);
    new ResizeObserver(()=>requestAnimationFrame(update)).observe(element);
    update();
    return {update,canOpen};
}
