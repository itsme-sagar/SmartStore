const KEY = "smartstore_data_v1";

const seed = {
  products: [
    {id:1,name:"Wireless Keyboard",sku:"KB-101",category:"Electronics",price:1299,stock:24,min:8},
    {id:2,name:"Wireless Mouse",sku:"MS-204",category:"Electronics",price:799,stock:7,min:10},
    {id:3,name:"USB-C Cable",sku:"CB-310",category:"Accessories",price:499,stock:38,min:10},
    {id:4,name:"Notebook A5",sku:"NB-405",category:"Stationery",price:99,stock:62,min:15},
    {id:5,name:"Ball Pen Pack",sku:"PN-511",category:"Stationery",price:149,stock:9,min:12},
    {id:6,name:"Water Bottle",sku:"WB-602",category:"Lifestyle",price:599,stock:0,min:8},
    {id:7,name:"Desk Lamp",sku:"DL-703",category:"Home",price:1499,stock:14,min:5},
    {id:8,name:"Power Bank",sku:"PB-804",category:"Electronics",price:1899,stock:16,min:6}
  ],
  customers: [
    {id:1,name:"Aarav Kumar",phone:"9876543210",email:"aarav@example.com"},
    {id:2,name:"Priya Sharma",phone:"9123456780",email:"priya@example.com"},
    {id:3,name:"Rahul Nair",phone:"9988776655",email:"rahul@example.com"}
  ],
  suppliers: [
    {id:1,name:"TechWorld Supplies",company:"TechWorld Pvt. Ltd.",phone:"9000011111",email:"sales@techworld.example",products:12},
    {id:2,name:"OfficeMart",company:"OfficeMart Traders",phone:"9000022222",email:"contact@officemart.example",products:18},
    {id:3,name:"HomePlus",company:"HomePlus Distributors",phone:"9000033333",email:"hello@homeplus.example",products:8}
  ],
  sales: [
    {id:"INV-1004",customer:"Priya Sharma",date:"2026-09-30",items:[{name:"Wireless Keyboard",qty:1}],total:1363.95},
    {id:"INV-1003",customer:"Aarav Kumar",date:"2026-09-29",items:[{name:"USB-C Cable",qty:2}],total:1047.90},
    {id:"INV-1002",customer:"Walk-in Customer",date:"2026-09-28",items:[{name:"Desk Lamp",qty:1}],total:1573.95}
  ]
};

let data = loadData();
let cart = [];
let editingProductId = null;
let editingCustomerId = null;
let editingSupplierId = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => [...document.querySelectorAll(s)];
const money = (n) => "₹" + Number(n || 0).toLocaleString("en-IN",{maximumFractionDigits:2});
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

function loadData(){
  try { return JSON.parse(localStorage.getItem(KEY)) || structuredClone(seed); }
  catch { return structuredClone(seed); }
}
function saveData(){ localStorage.setItem(KEY, JSON.stringify(data)); renderAll(); }
function nextId(list){ return list.length ? Math.max(...list.map(x => Number(x.id)||0))+1 : 1; }
function stockStatus(p){
  if(p.stock <= 0) return ["Out of stock","red"];
  if(p.stock <= p.min) return ["Low stock","orange"];
  return ["Healthy","green"];
}
function showToast(msg){
  const t=$("#toast"); t.textContent=msg; t.classList.add("show");
  clearTimeout(window.toastTimer); window.toastTimer=setTimeout(()=>t.classList.remove("show"),2200);
}

function navigate(section){
  $$(".section").forEach(s=>s.classList.toggle("active",s.id===section));
  $$(".nav-item").forEach(b=>b.classList.toggle("active",b.dataset.section===section));
  const titles={dashboard:"Dashboard",products:"Products",inventory:"Inventory","sales":"Sales & Billing",customers:"Customers",suppliers:"Suppliers",reports:"Reports"};
  $("#pageTitle").textContent=titles[section] || "Dashboard";
  if(window.innerWidth<=760) $("#sidebar").classList.remove("open");
  window.scrollTo({top:0,behavior:"smooth"});
}
$$(".nav-item").forEach(b=>b.addEventListener("click",()=>navigate(b.dataset.section)));
$$("[data-go]").forEach(b=>b.addEventListener("click",()=>navigate(b.dataset.go)));

function renderDashboard(){
  const revenue=data.sales.reduce((a,s)=>a+Number(s.total),0);
  const cats=new Set(data.products.map(p=>p.category)).size;
  const low=data.products.filter(p=>p.stock>0&&p.stock<=p.min).length;
  $("#statProducts").textContent=data.products.length;
  $("#statCategories").textContent=`${cats} categories`;
  $("#statRevenue").textContent=money(revenue);
  $("#statOrders").textContent=`${data.sales.length} orders`;
  $("#statLowStock").textContent=low;
  $("#statCustomers").textContent=data.customers.length;

  const recent=[...data.sales].reverse().slice(0,5);
  $("#recentSales").innerHTML=recent.length ? recent.map(s=>`<tr><td><b>${esc(s.id)}</b></td><td>${esc(s.customer)}</td><td>${esc(s.date)}</td><td>${s.items.reduce((a,i)=>a+i.qty,0)}</td><td><b>${money(s.total)}</b></td><td><span class="pill green">Completed</span></td></tr>`).join("") : `<tr><td colspan="6"><div class="empty">No sales yet.</div></td></tr>`;

  renderSalesChart();
  const healthy=data.products.filter(p=>p.stock>p.min).length, lows=low, out=data.products.filter(p=>p.stock<=0).length, total=data.products.length||1;
  const healthyPct=Math.round(healthy/total*100);
  $("#stockHealthy").textContent=healthyPct+"%"; $("#healthyCount").textContent=healthy; $("#lowCount").textContent=lows; $("#outCount").textContent=out;
  $("#stockDonut").style.background=`conic-gradient(var(--green) 0 ${healthy/total*100}%,var(--orange) ${healthy/total*100}% ${(healthy+lows)/total*100}%,var(--red) ${(healthy+lows)/total*100}% 100%)`;
}
function renderSalesChart(){
  const days=[]; const now=new Date();
  for(let i=6;i>=0;i--){const d=new Date(now);d.setDate(now.getDate()-i);const key=d.toISOString().slice(0,10);const value=data.sales.filter(s=>s.date===key).reduce((a,s)=>a+s.total,0);days.push({key,value,label:d.toLocaleDateString("en-IN",{weekday:"short"})});}
  const max=Math.max(...days.map(d=>d.value),1);
  $("#salesChart").innerHTML=days.map(d=>`<div class="bar-col"><div class="bar" style="height:${Math.max(4,d.value/max*82)}%" title="${money(d.value)}"></div><small>${d.label}</small></div>`).join("");
}

function populateCategories(){
  const select=$("#categoryFilter"); const current=select.value;
  const cats=[...new Set(data.products.map(p=>p.category))].sort();
  select.innerHTML=`<option value="all">All categories</option>`+cats.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join("");
  if(cats.includes(current)) select.value=current;
}
function renderProducts(){
  populateCategories();
  const q=$("#productSearch").value.toLowerCase(), cat=$("#categoryFilter").value, sf=$("#stockFilter").value;
  const sort=$("#productSort").value;
  let list=data.products.filter(p=>{
    const status=stockStatus(p)[1];
    return (!q || `${p.name} ${p.sku} ${p.category}`.toLowerCase().includes(q)) && (cat==="all"||p.category===cat) && (sf==="all"||sf===status);
  });
  list=[...list];
  if(sort==="name") list.sort((a,b)=>a.name.localeCompare(b.name));
  if(sort==="priceLow") list.sort((a,b)=>a.price-b.price);
  if(sort==="priceHigh") list.sort((a,b)=>b.price-a.price);
  if(sort==="stockLow") list.sort((a,b)=>a.stock-b.stock);
  $("#productTable").innerHTML=list.length?list.map(p=>{
    const [label,cls]=stockStatus(p);
    return `<tr><td><span class="product-name">${esc(p.name)}</span></td><td class="muted">${esc(p.sku)}</td><td>${esc(p.category)}</td><td><b>${money(p.price)}</b></td><td>${p.stock}</td><td><span class="pill ${cls}">${label}</span></td><td><div class="row-actions"><button class="small-btn" onclick="openProductModal(${p.id})">Edit</button><button class="small-btn danger" onclick="deleteProduct(${p.id})">Delete</button></div></td></tr>`;
  }).join(""):`<tr><td colspan="7"><div class="empty">No products found.</div></td></tr>`;
}
function renderInventory(){
  const healthy=data.products.filter(p=>p.stock>p.min).length, low=data.products.filter(p=>p.stock>0&&p.stock<=p.min).length, out=data.products.filter(p=>p.stock<=0).length;
  $("#inventoryCards").innerHTML=[
    ["Healthy Stock",healthy,"Products above minimum","green"],
    ["Low Stock",low,"Products needing reorder","orange"],
    ["Out of Stock",out,"Products unavailable","red"]
  ].map(x=>`<div class="inventory-mini"><h4>${x[0]}</h4><strong>${x[1]}</strong><p>${x[2]}</p></div>`).join("");
  $("#inventoryTable").innerHTML=data.products.map(p=>{
    const [label,cls]=stockStatus(p);
    return `<tr><td><b>${esc(p.name)}</b><br><span class="muted">${esc(p.sku)}</span></td><td><b>${p.stock}</b></td><td>${p.min}</td><td><span class="pill ${cls}">${label}</span></td><td><div class="row-actions"><button class="small-btn" onclick="adjustStock(${p.id},-1)">−1</button><button class="small-btn" onclick="adjustStock(${p.id},1)">＋1</button><button class="small-btn" onclick="adjustStock(${p.id},5)">＋5</button></div></td></tr>`;
  }).join("");
}
function renderPicker(){
  const q=$("#saleSearch").value.toLowerCase();
  const list=data.products.filter(p=>p.stock>0 && (!q||`${p.name} ${p.sku}`.toLowerCase().includes(q)));
  $("#productPicker").innerHTML=list.length?list.map(p=>`<div class="pick-card" onclick="addToCart(${p.id})"><h4>${esc(p.name)}</h4><p>${esc(p.sku)} · ${p.stock} available</p><b>${money(p.price)}</b></div>`).join(""):`<div class="empty">No available products.</div>`;
}
function addToCart(id){
  const p=data.products.find(x=>x.id===id); if(!p||p.stock<=0)return;
  const item=cart.find(x=>x.id===id);
  if(item){ if(item.qty>=p.stock)return showToast("Maximum available stock reached."); item.qty++; }
  else cart.push({id,qty:1});
  renderCart();
}
function renderCart(){
  const count=cart.reduce((a,x)=>a+x.qty,0); $("#cartCount").textContent=`${count} item${count===1?"":"s"}`;
  $("#cartItems").innerHTML=cart.length?cart.map(c=>{const p=data.products.find(x=>x.id===c.id);return `<div class="cart-row"><div><h4>${esc(p.name)}</h4><small>${money(p.price)} × ${c.qty}</small><div class="qty"><button onclick="changeCart(${p.id},-1)">−</button><span>${c.qty}</span><button onclick="changeCart(${p.id},1)">＋</button></div></div><b>${money(p.price*c.qty)}</b></div>`}).join(""):`<div class="empty-cart">Your cart is empty.<br>Add products to start a sale.</div>`;
  const sub=cart.reduce((a,c)=>{const p=data.products.find(x=>x.id===c.id);return a+p.price*c.qty},0);
  const disc=Math.max(0,Number($("#discount").value)||0), taxable=Math.max(0,sub-disc), tax=taxable*.05, total=taxable+tax;
  $("#billSubtotal").textContent=money(sub); $("#billTax").textContent=money(tax); $("#billTotal").textContent=money(total);
}
function changeCart(id,delta){
  const c=cart.find(x=>x.id===id); const p=data.products.find(x=>x.id===id); if(!c)return;
  c.qty+=delta; if(c.qty<=0)cart=cart.filter(x=>x.id!==id); if(c.qty>p.stock)c.qty=p.stock; renderCart();
}
$("#clearCart").addEventListener("click",()=>{cart=[];renderCart()});
$("#discount").addEventListener("input",renderCart);
$("#saleSearch").addEventListener("input",renderPicker);
$("#completeSale").addEventListener("click",()=>{
  if(!cart.length)return showToast("Add at least one product.");
  const customer=$("#saleCustomer").value || "Walk-in Customer";
  const sub=cart.reduce((a,c)=>a+data.products.find(p=>p.id===c.id).price*c.qty,0);
  const disc=Math.max(0,Number($("#discount").value)||0), tax=Math.max(0,sub-disc)*.05, total=Math.max(0,sub-disc)+tax;
  const items=cart.map(c=>{const p=data.products.find(x=>x.id===c.id);p.stock-=c.qty;return {name:p.name,qty:c.qty};});
  const num=1000+data.sales.length+1;
  data.sales.push({id:`INV-${num}`,customer,date:new Date().toISOString().slice(0,10),items,total:Number(total.toFixed(2))});
  cart=[];$("#discount").value=0;saveData();renderCart();renderPicker();showToast("Sale completed successfully.");
});

function renderSales(){
  const today=new Date().toISOString().slice(0,10);
  const todays=data.sales.filter(s=>s.date===today);
  const todayTotal=todays.reduce((a,s)=>a+s.total,0);
  $("#todaySales").textContent=money(todayTotal);
  $("#todayTransactions").textContent=todays.length;
  $("#averageBill").textContent=money(todays.length?todayTotal/todays.length:0);
  $("#salesTable").innerHTML=data.sales.slice().reverse().map(s=>`<tr><td><b>${esc(s.id)}</b></td><td>${esc(s.customer)}</td><td>${esc(s.date)}</td><td>${s.items.map(i=>`${esc(i.name)} × ${i.qty}`).join(", ")}</td><td><b>${money(s.total)}</b></td><td><button class="small-btn" onclick="showInvoice('${s.id}')">Invoice</button></td></tr>`).join("") || `<tr><td colspan="6"><div class="empty">No completed sales.</div></td></tr>`;
  $("#saleCustomer").innerHTML=`<option value="">Walk-in Customer</option>`+data.customers.map(c=>`<option value="${esc(c.name)}">${esc(c.name)}</option>`).join("");
}
function renderCustomers(){
  const q=$("#customerSearch").value.toLowerCase();
  const list=data.customers.filter(c=>`${c.name} ${c.phone} ${c.email}`.toLowerCase().includes(q));
  $("#customerTable").innerHTML=list.map(c=>{
    const sales=data.sales.filter(s=>s.customer===c.name); const spent=sales.reduce((a,s)=>a+s.total,0);
    return `<tr><td><b>${esc(c.name)}</b></td><td>${esc(c.phone)}</td><td>${esc(c.email)}</td><td>${sales.length}</td><td><b>${money(spent)}</b></td><td><div class="row-actions"><button class="small-btn" onclick="openCustomerModal(${c.id})">Edit</button><button class="small-btn danger" onclick="deleteCustomer(${c.id})">Delete</button></div></td></tr>`;
  }).join("")||`<tr><td colspan="6"><div class="empty">No customers found.</div></td></tr>`;
}
function renderSuppliers(){
  $("#supplierTable").innerHTML=data.suppliers.map(s=>`<tr><td><b>${esc(s.name)}</b></td><td>${esc(s.company)}</td><td>${esc(s.phone)}</td><td>${esc(s.email)}</td><td>${s.products}</td><td><div class="row-actions"><button class="small-btn" onclick="openSupplierModal(${s.id})">Edit</button><button class="small-btn danger" onclick="deleteSupplier(${s.id})">Delete</button></div></td></tr>`).join("")||`<tr><td colspan="6"><div class="empty">No suppliers added.</div></td></tr>`;
}
function renderReports(){
  const revenue=data.sales.reduce((a,s)=>a+s.total,0), inv=data.products.reduce((a,p)=>a+p.price*p.stock,0);
  const sold={}; data.sales.forEach(s=>s.items.forEach(i=>sold[i.name]=(sold[i.name]||0)+i.qty));
  const top=Object.entries(sold).sort((a,b)=>b[1]-a[1]); $("#reportRevenue").textContent=money(revenue);$("#reportInventory").textContent=money(inv);$("#reportTopProduct").textContent=top[0]?.[0]||"—";$("#reportAlerts").textContent=data.products.filter(p=>p.stock<=p.min).length;
  const max=top[0]?.[1]||1;
  $("#topProducts").innerHTML=top.slice(0,6).map((x,i)=>`<div class="rank-row"><div class="rank-num">${i+1}</div><div><b>${esc(x[0])}</b><div class="progress"><i style="width:${x[1]/max*100}%"></i></div></div><b>${x[1]}</b></div>`).join("")||`<div class="empty">Sales data will appear here.</div>`;
  const cats={};data.products.forEach(p=>cats[p.category]=(cats[p.category]||0)+1);const total=data.products.length||1;
  $("#categoryReport").innerHTML=Object.entries(cats).sort((a,b)=>b[1]-a[1]).map(x=>`<div class="cat-row"><span>${esc(x[0])}</span><b>${x[1]} (${Math.round(x[1]/total*100)}%)</b></div>`).join("");
}

function openModal(html){$("#modal").innerHTML=html;$("#modalBackdrop").classList.add("open")}
function closeModal(){$("#modalBackdrop").classList.remove("open")}
$("#modalBackdrop").addEventListener("click",e=>{if(e.target.id==="modalBackdrop")closeModal()});

function openProductModal(id=null){
  editingProductId=id;const p=id?data.products.find(x=>x.id===id):{name:"",sku:"",category:"Electronics",price:"",stock:"",min:5};
  openModal(`<h3>${id?"Edit":"Add"} Product</h3><p>Enter the product information below.</p><form id="productForm"><div class="form-grid">
  <div class="form-field full"><label>Product Name *</label><input name="name" required value="${esc(p.name)}"></div>
  <div class="form-field"><label>SKU *</label><input name="sku" required value="${esc(p.sku)}"></div>
  <div class="form-field"><label>Category *</label><input name="category" required value="${esc(p.category)}"></div>
  <div class="form-field"><label>Price (₹) *</label><input name="price" type="number" min="0" step=".01" required value="${p.price}"></div>
  <div class="form-field"><label>Stock *</label><input name="stock" type="number" min="0" required value="${p.stock}"></div>
  <div class="form-field"><label>Minimum Stock *</label><input name="min" type="number" min="0" required value="${p.min}"></div>
  </div><div class="modal-actions"><button type="button" class="secondary-btn" onclick="closeModal()">Cancel</button><button class="primary-btn">${id?"Save Changes":"Add Product"}</button></div></form>`);
  $("#productForm").addEventListener("submit",e=>{
    e.preventDefault();
    const f=new FormData(e.target);
    const obj={name:f.get("name").trim(),sku:f.get("sku").trim(),category:f.get("category").trim(),price:Number(f.get("price")),stock:Number(f.get("stock")),min:Number(f.get("min"))};
    if(!obj.name||!obj.sku||!obj.category||obj.price<0||obj.stock<0||obj.min<0){showToast("Please enter valid product details.");return;}
    const duplicate=data.products.some(x=>x.sku.toLowerCase()===obj.sku.toLowerCase() && x.id!==editingProductId);
    if(duplicate){showToast("SKU already exists. Use a unique SKU.");return;}
    if(editingProductId){Object.assign(data.products.find(x=>x.id===editingProductId),obj);showToast("Product updated.")}else{data.products.push({id:nextId(data.products),...obj});showToast("Product added.")}
    closeModal();saveData()
  });
}
function deleteProduct(id){if(confirm("Delete this product?")){data.products=data.products.filter(p=>p.id!==id);saveData();showToast("Product deleted.")}}
function adjustStock(id,delta){const p=data.products.find(x=>x.id===id);p.stock=Math.max(0,p.stock+delta);saveData();showToast(`${p.name} stock updated.`)}

function openCustomerModal(id=null){
  editingCustomerId=id;const c=id?data.customers.find(x=>x.id===id):{name:"",phone:"",email:""};
  openModal(`<h3>${id?"Edit":"Add"} Customer</h3><p>Store customer contact details.</p><form id="customerForm"><div class="form-grid">
  <div class="form-field full"><label>Name *</label><input name="name" required value="${esc(c.name)}"></div>
  <div class="form-field"><label>Phone *</label><input name="phone" required value="${esc(c.phone)}"></div>
  <div class="form-field"><label>Email</label><input name="email" type="email" value="${esc(c.email)}"></div>
  </div><div class="modal-actions"><button type="button" class="secondary-btn" onclick="closeModal()">Cancel</button><button class="primary-btn">Save Customer</button></div></form>`);
  $("#customerForm").addEventListener("submit",e=>{e.preventDefault();const f=new FormData(e.target);const obj={name:f.get("name").trim(),phone:f.get("phone").trim(),email:f.get("email").trim()};if(!obj.name||!obj.phone){showToast("Name and phone are required.");return;}if(!/^\\d{10}$/.test(obj.phone)){showToast("Enter a valid 10-digit phone number.");return;}if(editingCustomerId)Object.assign(data.customers.find(x=>x.id===editingCustomerId),obj);else data.customers.push({id:nextId(data.customers),...obj});closeModal();saveData();showToast("Customer saved.")});
}
function deleteCustomer(id){if(confirm("Delete this customer?")){data.customers=data.customers.filter(c=>c.id!==id);saveData();showToast("Customer deleted.")}}

function openSupplierModal(id=null){
  editingSupplierId=id;const s=id?data.suppliers.find(x=>x.id===id):{name:"",company:"",phone:"",email:"",products:0};
  openModal(`<h3>${id?"Edit":"Add"} Supplier</h3><p>Store supplier contact details.</p><form id="supplierForm"><div class="form-grid">
  <div class="form-field"><label>Contact Name *</label><input name="name" required value="${esc(s.name)}"></div>
  <div class="form-field"><label>Company *</label><input name="company" required value="${esc(s.company)}"></div>
  <div class="form-field"><label>Phone *</label><input name="phone" required value="${esc(s.phone)}"></div>
  <div class="form-field"><label>Email</label><input name="email" type="email" value="${esc(s.email)}"></div>
  <div class="form-field"><label>Products Supplied</label><input name="products" type="number" min="0" value="${s.products}"></div>
  </div><div class="modal-actions"><button type="button" class="secondary-btn" onclick="closeModal()">Cancel</button><button class="primary-btn">Save Supplier</button></div></form>`);
  $("#supplierForm").addEventListener("submit",e=>{e.preventDefault();const f=new FormData(e.target);const obj={name:f.get("name").trim(),company:f.get("company").trim(),phone:f.get("phone").trim(),email:f.get("email").trim(),products:Number(f.get("products"))||0};if(editingSupplierId)Object.assign(data.suppliers.find(x=>x.id===editingSupplierId),obj);else data.suppliers.push({id:nextId(data.suppliers),...obj});closeModal();saveData();showToast("Supplier saved.")});
}
function deleteSupplier(id){if(confirm("Delete this supplier?")){data.suppliers=data.suppliers.filter(s=>s.id!==id);saveData();showToast("Supplier deleted.")}}

function showInvoice(id){
  const s=data.sales.find(x=>x.id===id);if(!s)return;
  openModal(`<div class="invoice-printable">
    <div class="invoice-head">
      <div><div class="invoice-brand">SmartStore</div><div class="muted">Inventory & Sales Management</div></div>
      <div class="invoice-meta"><b>${esc(s.id)}</b><br>${esc(s.date)}<br>${esc(s.customer)}</div>
    </div>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Qty</th></tr></thead><tbody>${s.items.map(i=>`<tr><td>${esc(i.name)}</td><td>${i.qty}</td></tr>`).join("")}</tbody></table></div>
    <div class="invoice-total"><span>Grand Total</span><b>${money(s.total)}</b></div>
  </div>
  <div class="modal-actions"><button class="secondary-btn" onclick="window.print()">Print Invoice</button><button class="primary-btn" onclick="closeModal()">Close</button></div>`);
}

$("#addProductBtn").addEventListener("click",()=>openProductModal());
$("#addCustomerBtn").addEventListener("click",()=>openCustomerModal());
$("#addSupplierBtn").addEventListener("click",()=>openSupplierModal());
$("#productSearch").addEventListener("input",renderProducts);
$("#categoryFilter").addEventListener("change",renderProducts);
$("#stockFilter").addEventListener("change",renderProducts);
$("#productSort").addEventListener("change",renderProducts);
$("#customerSearch").addEventListener("input",renderCustomers);
$("#menuBtn").addEventListener("click",()=>$("#sidebar").classList.toggle("open"));
$("#themeBtn").addEventListener("click",()=>{document.body.classList.toggle("dark");localStorage.setItem("smartstore_theme",document.body.classList.contains("dark")?"dark":"light")});
if(localStorage.getItem("smartstore_theme")==="dark")document.body.classList.add("dark");

$("#exportBtn").addEventListener("click",()=>{
  const blob=new Blob([JSON.stringify(data,null,2)],{type:"application/json"}),url=URL.createObjectURL(blob),a=document.createElement("a");
  a.href=url;a.download="smartstore-data.json";a.click();URL.revokeObjectURL(url);showToast("Data exported.");
});
document.addEventListener("keydown",e=>{if(e.key==="Escape")closeModal();if(e.key==="n"&&e.ctrlKey){e.preventDefault();navigate("sales");}});
$("#today").textContent=new Date().toLocaleDateString("en-IN",{day:"2-digit",month:"short",year:"numeric"});

function renderAll(){
  renderDashboard();renderProducts();renderInventory();renderPicker();renderCart();renderSales();renderCustomers();renderSuppliers();renderReports();
}
renderAll();
