#! /usr/bin/env python3
import sys
import json

jsonstringobj = sys.argv[1]

print (jsonstringobj)


obj2 = json.loads(jsonstringobj)

print(obj2["arg1"])
print(obj2["arg2"])
print(obj2["arg3"])












