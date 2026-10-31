export const hubTabs={domains:["Domény","Přiřazení adres a lokální simulace DNS a certifikátů."],inbox:['Testovací pošta','Lokální simulátor e-mailů · přístup operátora.'],'customer-view':['Pohled zákazníka','Samostatný účet, jeho weby a předplatná.'],instances:['Instance','Provoz, balíčky a přístup k zákaznickým webům.'],customers:['Zákazníci','Zákazníci a jejich propojené instance.'],packages:['Balíčky','Ceny, moduly, funkce a šablony na jednom místě.'],releases:['Verze platformy','Registrace obrazů a dostupnost verzí pro nasazení.'],activity:['Úlohy','Průběh a výsledky provozních operací.'],settings:['Nastavení','Pravidla předplatného a lokální simulace.']};
export const instanceTabs={overview:'Přehled',package:'Balíček',billing:'Předplatné',release:'Verze a obnova',access:'Přístup',history:'Historie'};
export function parseTabHash(hash){
 let value;try{value=decodeURIComponent(hash.replace(/^#\/?/,''));}catch{return {tab:'instances',site:'',detail:'overview'};}
 if(value==='package-editor')value='packages';if(value==='release-panel')value='releases';
 const [tab,site='',detail='overview']=value.split('/');
 if(Object.hasOwn(hubTabs,tab))return {tab,site:tab==='instances'?site:'',detail:Object.hasOwn(instanceTabs,detail)?detail:'overview'};
 return {tab:'instances',site:/^[a-z][a-z0-9-]{2,39}$/.test(value)?value:'',detail:'overview'};
}
export function tabHash(tab,site='',detail='overview'){return `#${tab}${tab==='instances'&&site?`/${encodeURIComponent(site)}/${detail}`:''}`;}
