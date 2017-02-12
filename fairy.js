/**
*@description:基础精灵，基本的宽高、位置、背景图片
*其中img:{url:图片路径，left:水平偏移，top:垂直偏移}
*/
function BaseFairy(width,height,x,y,img)
{
	this.objPool = null;  //所在对象池的引用
	this.vm = null;
	this.layerName = '';
	this.position = 'absolute';
    this.width = width + 'px';
	this.height = height + 'px';
	this.left = x + 'px';
	this.top = y + 'px';
	if(typeof(img) == 'object')
	{
	    this.background = 'url('+img.url+') no-repeat '+img.left+'% '+img.top+'%';	
	}else{
		this.background = '';	
	}
	
	
	this.getter = function(key)
	{
		return parseInt(this[key]);
	}
	this.addToVm = function(vm,layerName,frameFunc)
	{
		if(this.vm != null) return;
		this.vm = vm;
		this.layerName = layerName;
		this.vm.addFairy(this,layerName);
		this.objPool = vm[layerName].fairyList;
		if(typeof(frameFunc) != 'function')
		{
			this.frameList[0] = function(){};
		}else{
			this.frameList[0] = frameFunc;
		}
		this.frameList[0].call(this);
		
	}
}
/**
*@description:活动精灵，实现帧、移动
*@param FUNCTION frameFunc 第一帧的回调
*/
function ActionFairy(width,height,x,y,img)
{
	BaseFairy.call(this,width,height,x,y,img);
	this.frameList = [];  //帧列表
	this.curFrame = 0;    //当前第几帧
	this.fps = 1;        //帧率
	var timer;//存储定时器id
	
	//获取在对象池中的引用
	this.getSelfForPool = function()
	{
		return this.objPool[parseInt(this.left)+'_'+parseInt(this.top)];
	}
	//移动到某个像素
	this.moveTo = function(x,y)
	{
		var left = this.getter('left');
		var top =  this.getter('top');
		if(left == x && top == y) return;
		var poolObj = this.getSelfForPool();
		poolObj.left = x + 'px';
		poolObj.top =  y + 'px';
		/*更新地图坐标点*/
		this.vm[this.layerName].fairyList[x+'_'+y] = this.vm[this.layerName].fairyList[left+'_'+top];
		this.vm[this.layerName].fairyList[left+'_'+top] = null;
		this.vm.reload(); //顺便重绘
	}
	//水平移动x个像素
	this.xMove = function(x)
	{
		var left = this.getter('left');
		var top = this.getter('top');
		var poolObj = this.getSelfForPool();
		var nowX = parseInt(poolObj.left);
		var newX = nowX + x ;
		poolObj.left = newX + 'px';
		/*更新地图坐标点*/
		this.vm[this.layerName].fairyList[newX+'_'+top] = this.vm[this.layerName].fairyList[left+'_'+top];
		this.vm[this.layerName].fairyList[left+'_'+top] = null;
		this.vm.reload(); //顺便重绘
	}
	//垂直移动y个像素
	this.yMove = function(y)
	{
		var left = this.getter('left');
		var top = this.getter('top');
		var poolObj = this.getSelfForPool();
		var nowY = parseInt(poolObj.top);
		var newY = nowY + y ;
		poolObj.top = newY + 'px';
		/*更新地图坐标点*/
		this.vm[this.layerName].fairyList[left+'_'+newY] = this.vm[this.layerName].fairyList[left+'_'+top];
		this.vm[this.layerName].fairyList[left+'_'+top] = null;
		this.vm.reload(); //顺便重绘
	}
	//设置背景图片
	this.setBackground = function(img)
	{
		var poolObj = this.getSelfForPool();
		poolObj.background = 'url('+img.url+') no-repeat '+img.left+'% '+img.top+'%';
		this.vm.reload();
	}
	//添加一帧
	this.addFrame = function(func)
	{
		this.frameList.push(func);
	}
	//按当前顺序播放帧
	this.play = function()
	{
		var self = this;
		if(typeof(timer) != 'undefined') return;
		if(this.frameList.length <= 1) return;
		
		timer = setInterval(function(){
			self.curFrame+= 1
			if(self.curFrame > self.frameList.length-1) self.curFrame = 0;
			var func = self.frameList[self.curFrame];
			if(typeof(func) != 'function') return;
			func.call(self);
		},1000/this.fps);
	}
	this.stop = function()
	{
		if(typeof(timer) == 'undefined') return;
		clearInterval(timer);
		timer = undefined;
	}
}