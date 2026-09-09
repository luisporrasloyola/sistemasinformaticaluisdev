(() => {
 const btn=document.getElementById('viewMyScheduleBtn'), modalEl=document.getElementById('myScheduleModal');
 if(!btn||!modalEl)return;
 const modal=bootstrap.Modal.getOrCreateInstance(modalEl), worker=document.getElementById('markWorkerId'), loading=document.getElementById('myScheduleLoading'), legend=document.getElementById('myScheduleLegend'), box=document.getElementById('myScheduleCalendar'), detail=document.getElementById('myScheduleDetail');
 let calendar=null;
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const time=v=>v?String(v).slice(0,5):'--:--';
 const key=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
 function events(data){
  const result=[], blocked=new Set(), programmed=new Set();
  (data.calendar_events||[]).forEach(x=>{let d=new Date(`${x.start_date}T12:00:00`),last=new Date(`${x.end_date}T12:00:00`);while(d<=last){blocked.add(key(d));d.setDate(d.getDate()+1)}last.setDate(last.getDate()+1);result.push({title:`${x.code} · ${x.label}`,start:x.start_date,end:key(last),allDay:true,color:'#2563eb',extendedProps:{...x,kind:'calendar'}})});
  (data.programs||[]).forEach(x=>{programmed.add(x.program_date);if(!blocked.has(x.program_date))result.push({title:`${time(x.entry_time)} - ${time(x.exit_time)} · ${x.location_name}`,start:x.program_date,allDay:true,color:'#f97316',extendedProps:{...x,kind:'program'}})});
  const now=new Date(),start=new Date(now.getFullYear(),now.getMonth()-3,now.getDate(),12),end=new Date(now.getFullYear(),now.getMonth()+12,now.getDate(),12),overrides=new Map((data.journey_overrides||[]).map(x=>[`${x.assignment_id}-${x.journey_date}`,x]));
  for(let d=new Date(start);d<=end;d.setDate(d.getDate()+1)){const date=key(d),day=d.getDay()||7;if(blocked.has(date)||programmed.has(date))continue;(data.regular_schedules||[]).forEach(x=>{if(+x.day_of_week!==day||date<x.valid_from||(x.valid_until&&date>x.valid_until))return;result.push({title:`${time(x.entry_time)} - ${time(x.exit_time)} · ${x.location_name}`,start:date,allDay:true,color:'#16a34a',extendedProps:{...x,...(overrides.get(`${x.assignment_id}-${date}`)||{}),kind:'regular'}})})}
  return result;
 }
 function show(event){const x=event.extendedProps;if(x.kind==='calendar')detail.innerHTML=`<strong>${esc(x.label)}</strong><div>${esc(x.name||'Calendario laboral')}</div>`;else detail.innerHTML=`<strong>${x.kind==='regular'?'Horario habitual':'Programación especial'}</strong><div class="row g-2 mt-1"><div class="col-md-4"><small class="text-muted d-block">Horario</small>${time(x.entry_time)} - ${time(x.exit_time)}</div><div class="col-md-4"><small class="text-muted d-block">Lugar de marcación</small>${esc(x.location_name||'-')}</div><div class="col-md-4"><small class="text-muted d-block">Proyecto / actividad</small>${esc(x.activity||'-')}</div></div>`;detail.classList.remove('d-none')}
 btn.addEventListener('click',async()=>{
  const id=worker?.value||'';if(!id){window.Swal?.fire('Seleccione un trabajador','Primero seleccione el trabajador cuya programación desea consultar.','info');return}
  loading.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando programación...';loading.classList.remove('d-none');legend.classList.add('d-none');box.classList.add('d-none');detail.classList.add('d-none');modal.show();
  try{const response=await fetch(`${window.APP_URL}/servicios/control_personal/listar_programacion_trabajador.php?worker_id=${encodeURIComponent(id)}`),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'No se pudo consultar la programación.');if(!window.FullCalendar)throw new Error('No se pudo cargar el calendario.');calendar?.destroy();calendar=new FullCalendar.Calendar(box,{locale:'es',initialView:innerWidth<768?'listMonth':'dayGridMonth',firstDay:1,height:'auto',dayMaxEvents:3,displayEventTime:false,headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,listMonth'},buttonText:{today:'Hoy',month:'Mes',list:'Lista'},noEventsContent:'No hay jornadas programadas en este periodo.',events:events(data),eventClick:({event})=>show(event)});loading.classList.add('d-none');legend.classList.remove('d-none');box.classList.remove('d-none');calendar.render()}catch(error){loading.innerHTML=`<div class="alert alert-danger mb-0">${esc(error.message)}</div>`}
 });
 modalEl.addEventListener('shown.bs.modal',()=>calendar?.updateSize());
})();