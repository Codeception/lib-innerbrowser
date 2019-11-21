<html>
<body>

<div>
    <form action="/form/button" method="POST" id="form-id">
        <input type="hidden" name="text" value="val" />
        <button type="submit" name="btn0">Submit</button>
        <input id="button2" type="submit" form="form-nonexistent" value="Invalid form2" />
        <input type="submit" value="Should not submit" form="" />
    </form>
</div>

<div>
    <input type="submit" form="form-id" value="Submit 2" />
    <input type="submit" value="Outside submit" />

    <input id="button2" type="submit" form="form-nonexistent" value="Invalid form" />
</div>
</body>
</html>